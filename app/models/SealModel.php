<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * QR-verifiable printed documents (document_seals).
 *
 * When a sheet is printed, its content is fingerprinted (SHA-256 of a
 * snapshot built from the records), the seal is recorded on the blockchain
 * ledger, and the sheet carries a QR code with a random token. Anyone who
 * scans it sees whether the records still match the paper, whether the
 * seal's ledger block and the chain before it are intact, and whether that
 * part of the chain is anchored in Bitcoin.
 *
 * The public answer names only what is needed to match the paper (document
 * type, issuing center, date, and a masked student number or the batch name).
 */
class SealModel extends Model
{
    protected $table = 'document_seals';
    protected $primary_key = 'id';

    public const KINDS = [
        'student_info' => 'Student information sheet',
        'student_checklist' => 'Student document checklist',
        'enrollment_packet' => 'Student enrollment packet',
        'batch_records' => 'Batch student records',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->call->library('Ledger');
    }

    private function q()
    {
        return fn ($sql, $params = []) => $this->db->raw($sql, $params);
    }

    /**
     * The seal for a sheet as it is now: the existing one when nothing changed
     * since it was issued, otherwise a new seal recorded on the ledger.
     * Returns the seal row, or null when the record does not exist.
     */
    public function issue($kind, $entityId, $userId)
    {
        $snapshot = $this->snapshot($kind, $entityId);
        if (!$snapshot) {
            return null;
        }
        $hash = Ledger::data_hash($snapshot['data']);
        $existing = $this->db->raw(
            'SELECT id FROM document_seals WHERE kind = ? AND entity_id = ? AND snapshot_hash = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1',
            [$kind, (int) $entityId, $hash]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return $this->find_seal($existing['id']);
        }
        $this->db->transaction();
        try {
            $token = bin2hex(random_bytes(12));
            $this->db->table('document_seals')->insert([
                'token' => $token,
                'kind' => $kind,
                'entity_id' => (int) $entityId,
                'training_center_id' => $snapshot['center_id'],
                'snapshot_hash' => $hash,
                'issued_by' => $userId,
                'issued_at' => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->last_id();
            $this->call->model('AuditModel');
            $blockHash = $this->AuditModel->record('seal', $id, 'issued', $userId);
            $block = $this->db->raw('SELECT id FROM blockchain_ledger WHERE block_hash = ?', [$blockHash])->fetch(PDO::FETCH_ASSOC);
            $this->db->table('document_seals')->where('id', $id)->update(['ledger_id' => (int) $block['id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
        return $this->find_seal($id);
    }

    public function find_seal($id)
    {
        return $this->db->raw('SELECT s.*, l.block_index, l.block_hash FROM document_seals s LEFT JOIN blockchain_ledger l ON l.id = s.ledger_id WHERE s.id = ?', [(int) $id])
            ->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function by_token($token)
    {
        if (!preg_match('/^[a-f0-9]{24}$/', (string) $token)) {
            return null;
        }
        $row = $this->db->raw('SELECT id FROM document_seals WHERE token = ?', [$token])->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->find_seal($row['id']) : null;
    }

    /** The center a record belongs to (for access checks before issuing). */
    public function center_of($kind, $entityId)
    {
        $snapshot = $this->snapshot($kind, $entityId);
        return $snapshot ? $snapshot['center_id'] : null;
    }

    /**
     * Public verification of a printed sheet. Never returns personal data
     * beyond the masked subject needed to match the paper.
     */
    public function verify_public($token)
    {
        $seal = $this->by_token($token);
        if (!$seal) {
            return ['status' => 'unknown'];
        }
        $snapshot = $this->snapshot($seal['kind'], $seal['entity_id']);
        $unchanged = $snapshot && hash_equals($seal['snapshot_hash'], Ledger::data_hash($snapshot['data']));

        // The seal's own block, and the chain from the genesis block up to it.
        $this->call->model('LedgerModel');
        $blockOk = false;
        $chainOk = false;
        if ($seal['ledger_id']) {
            $block = $this->db->raw('SELECT * FROM blockchain_ledger WHERE id = ?', [(int) $seal['ledger_id']])->fetch(PDO::FETCH_ASSOC);
            $blockOk = $block && $this->LedgerModel->verify_block($block, null, false)['status'] === 'verified';
            $chain = $this->LedgerModel->verify_chain();
            $chainOk = $chain['valid'] || (int) $chain['first_broken']['block_index'] > (int) $seal['block_index'];
        }

        // The first anchor at or after the seal's block covers it.
        $anchor = $this->db->raw(
            "SELECT status, block_index, bitcoin_height, bitcoin_block, bitcoin_time, submitted_at FROM blockchain_anchors
              WHERE block_index >= ? ORDER BY (status = 'confirmed') DESC, block_index ASC LIMIT 1",
            [(int) $seal['block_index']]
        )->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($seal['revoked_at']) {
            $status = 'revoked';
        } elseif (!$blockOk || !$chainOk) {
            $status = 'tampered';
        } elseif (!$unchanged) {
            $status = 'changed';
        } else {
            $status = 'authentic';
        }
        $center = $seal['training_center_id']
            ? $this->db->raw('SELECT name FROM training_centers WHERE id = ?', [(int) $seal['training_center_id']])->fetch(PDO::FETCH_ASSOC)
            : null;
        return [
            'status' => $status,
            'document' => [
                'kind' => $seal['kind'],
                'title' => self::KINDS[$seal['kind']] ?? $seal['kind'],
                'center' => $center['name'] ?? null,
                'subject' => $snapshot['subject'] ?? null,
                'issued_at' => $seal['issued_at'],
                'fingerprint' => $seal['snapshot_hash'],
            ],
            'ledger' => [
                'block_index' => $seal['block_index'] === null ? null : (int) $seal['block_index'],
                'block_hash' => $seal['block_hash'],
                'block_intact' => $blockOk,
                'chain_intact' => $chainOk,
            ],
            'anchor' => $anchor ? [
                'status' => $anchor['status'],
                'bitcoin_height' => $anchor['bitcoin_height'] === null ? null : (int) $anchor['bitcoin_height'],
                'bitcoin_block' => $anchor['bitcoin_block'],
                'bitcoin_time' => $anchor['bitcoin_time'],
                'submitted_at' => $anchor['submitted_at'],
            ] : null,
        ];
    }

    /* ---------------------------------------------------------------- */

    /**
     * ['data' => snapshot, 'center_id' => int, 'subject' => public label] built
     * from today's records, or null when the record does not exist.
     */
    private function snapshot($kind, $entityId)
    {
        $q = $this->q();
        if ($kind === 'batch_records') {
            $b = $q('SELECT b.id, b.course_id, b.batch_name, b.start_date, b.end_date, b.status, b.completed_at, b.instructor_id,
                            c.course_name, c.course_code, c.training_center_id
                       FROM batches b INNER JOIN courses c ON c.id = b.course_id WHERE b.id = ?', [(int) $entityId])->fetch(PDO::FETCH_ASSOC);
            if (!$b) {
                return null;
            }
            $students = $q('SELECT student_id, status FROM batch_students WHERE batch_id = ? ORDER BY student_id', [(int) $entityId])->fetchAll(PDO::FETCH_ASSOC);
            $sessions = $q('SELECT id, session_date, hours, status FROM batch_sessions WHERE batch_id = ? ORDER BY session_date', [(int) $entityId])->fetchAll(PDO::FETCH_ASSOC);
            $marks = $sessions ? $q('SELECT session_id, student_id, status FROM batch_attendance WHERE session_id IN (' . implode(',', array_map('intval', array_column($sessions, 'id'))) . ') ORDER BY session_id, student_id')->fetchAll(PDO::FETCH_ASSOC) : [];
            return [
                'center_id' => (int) $b['training_center_id'],
                'subject' => ['label' => "{$b['batch_name']} · {$b['course_name']} ({$b['course_code']})", 'detail' => count($students) . ' trainees on record'],
                'data' => [
                    'batch' => ['id' => (int) $b['id'], 'course_id' => (int) $b['course_id'], 'name' => $b['batch_name'], 'start' => $b['start_date'],
                                'end' => $b['end_date'], 'status' => $b['status'], 'completed_at' => $b['completed_at'],
                                'instructor_id' => $b['instructor_id'] === null ? null : (int) $b['instructor_id']],
                    'students' => array_map(fn ($s) => [(int) $s['student_id'], $s['status']], $students),
                    'sessions' => array_map(fn ($s) => [$s['session_date'], $s['hours'], $s['status']], $sessions),
                    'attendance' => array_map(fn ($m) => [(int) $m['session_id'], (int) $m['student_id'], $m['status']], $marks),
                ],
            ];
        }

        $s = $q("SELECT u.id, u.training_center_id, sp.student_number, sp.first_name, sp.middle_name, sp.last_name, sp.birthdate, sp.sex, sp.civil_status
                   FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE u.id = ? AND u.role = 'student'", [(int) $entityId])->fetch(PDO::FETCH_ASSOC);
        if (!$s || !isset(self::KINDS[$kind])) {
            return null;
        }
        $data = ['student' => [
            'id' => (int) $s['id'], 'center_id' => (int) $s['training_center_id'], 'number' => $s['student_number'],
            'name' => [$s['last_name'], $s['first_name'], $s['middle_name']], 'birthdate' => $s['birthdate'],
            'sex' => $s['sex'], 'civil_status' => $s['civil_status'],
        ]];
        if ($kind === 'student_info' || $kind === 'enrollment_packet') {
            $rows = $q('SELECT bs.batch_id, bs.status FROM batch_students bs INNER JOIN batches b ON b.id = bs.batch_id
                         INNER JOIN courses c ON c.id = b.course_id WHERE bs.student_id = ? AND c.training_center_id = ? ORDER BY bs.batch_id',
                [(int) $s['id'], (int) $s['training_center_id']])->fetchAll(PDO::FETCH_ASSOC);
            $data['enrollments'] = array_map(fn ($r) => [(int) $r['batch_id'], $r['status']], $rows);
        }
        if ($kind === 'student_checklist' || $kind === 'enrollment_packet') {
            $docs = $q('SELECT d.id, d.requirement_type_id, d.status, d.verified_at FROM student_documents d
                         WHERE d.student_id = ? AND d.training_center_id = ? ORDER BY d.requirement_type_id',
                [(int) $s['id'], (int) $s['training_center_id']])->fetchAll(PDO::FETCH_ASSOC);
            $data['documents'] = array_map(function ($d) use ($q) {
                $files = $q('SELECT file_hash FROM student_document_files WHERE student_document_id = ? AND is_current = 1 ORDER BY id', [(int) $d['id']])->fetchAll(PDO::FETCH_ASSOC);
                return [(int) $d['requirement_type_id'], $d['status'], $d['verified_at'], array_column($files, 'file_hash')];
            }, $docs);
        }
        $initials = implode('. ', array_filter(array_map(fn ($n) => $n ? mb_strtoupper(mb_substr($n, 0, 1)) : null, [$s['first_name'], $s['middle_name'], $s['last_name']]))) . '.';
        $number = (string) $s['student_number'];
        return [
            'center_id' => (int) $s['training_center_id'],
            'subject' => [
                'label' => 'Student ' . ($number !== '' ? substr($number, 0, -4) . '••' . substr($number, -2) : '(no number)'),
                'detail' => "Initials {$initials}",
            ],
            'data' => $data,
        ];
    }
}
