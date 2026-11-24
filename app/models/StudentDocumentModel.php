<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Student enrollment documents, kept per student AND learning center
 * (never per course), so a student's verified documents are reused for every
 * later enrollment at the same center and are invisible to other centers.
 *
 *   requirement_types         what to submit, how many copies, and conditions
 *   student_documents         one row per (center, student, requirement): status + remarks
 *   student_document_files    the uploaded copies (replaced copies kept, is_current = 0)
 *   student_document_reviews  every upload, review, verification, rejection and reuse
 *
 * Status flow: not_submitted -> submitted -> under_review -> verified
 *                                   ^              |
 *                                   +-- rejected <-+   (student replaces the copies)
 */
class StudentDocumentModel extends Model
{
    protected $table = 'student_documents';
    protected $primary_key = 'id';
    protected $fillable = [
        'training_center_id', 'student_id', 'requirement_type_id', 'status', 'remarks', 'submitted_at',
        'uploaded_by', 'verified_by', 'verified_at',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->call->library('FileStore'); // LavaLust loader (app/libraries/FileStore.php)
    }

    /** Active requirement types in checklist order. */
    public function types()
    {
        return $this->db->table('requirement_types')
            ->select('id, code, name, description, is_required, required_copies, condition_code, sort_order')
            ->where('is_active', 1)
            ->order_by('sort_order', 'ASC')
            ->get_all();
    }

    public function type($typeId)
    {
        return $this->db->table('requirement_types')->where(['id' => (int) $typeId, 'is_active' => 1])->get() ?: null;
    }

    /** Does a requirement apply to this student? (Marriage contract: married only.) */
    public static function applies(array $type, $civilStatus)
    {
        return empty($type['condition_code']) || $type['condition_code'] === $civilStatus;
    }

    /**
     * The student's checklist at a center: every active requirement with its
     * status, current copies and remarks, plus a completeness summary.
     */
    public function checklist($centerId, $studentId, $civilStatus)
    {
        $types = $this->types();
        $docs = $this->db->table('student_documents d')
            ->select('d.id, d.requirement_type_id, d.status, d.remarks, d.submitted_at, d.verified_at, d.updated_at,
                      v.full_name AS verified_by_name')
            ->left_join('users v', 'v.id = d.verified_by')
            ->where(['d.training_center_id' => (int) $centerId, 'd.student_id' => (int) $studentId])
            ->get_all();
        $docsByType = array_column($docs, null, 'requirement_type_id');

        $files = [];
        if ($docs) {
            $rows = $this->db->table('student_document_files f')
                ->select('f.id, f.student_document_id, f.original_name, f.mime_type, f.file_size, f.uploaded_at,
                          u.full_name AS uploaded_by_name')
                ->left_join('users u', 'u.id = f.uploaded_by')
                ->in('f.student_document_id', array_column($docs, 'id'))
                ->where('f.is_current', 1)
                ->order_by('f.id', 'ASC')
                ->get_all();
            foreach ($rows as $row) {
                $files[$row['student_document_id']][] = $row;
            }
        }

        $items = [];
        $summary = ['required' => 0, 'verified' => 0, 'submitted' => 0, 'rejected' => 0, 'missing' => 0];
        foreach ($types as $type) {
            $doc = $docsByType[$type['id']] ?? null;
            $applies = self::applies($type, $civilStatus);
            $required = $applies && (int) $type['is_required'];
            $status = $doc['status'] ?? 'not_submitted';
            $copies = $doc ? ($files[$doc['id']] ?? []) : [];
            $items[] = [
                'requirement_type_id' => (int) $type['id'],
                'code' => $type['code'],
                'name' => $type['name'],
                'description' => $type['description'],
                'required_copies' => (int) $type['required_copies'],
                'condition_code' => $type['condition_code'],
                'applies' => $applies,
                'required' => $required,
                'document_id' => $doc ? (int) $doc['id'] : null,
                'status' => $status,
                'remarks' => $doc['remarks'] ?? null,
                'submitted_at' => $doc['submitted_at'] ?? null,
                'verified_at' => $doc['verified_at'] ?? null,
                'verified_by_name' => $doc['verified_by_name'] ?? null,
                'uploaded_copies' => count($copies),
                'files' => $copies,
            ];
            if ($required) {
                $summary['required']++;
                if ($status === 'verified') {
                    $summary['verified']++;
                } elseif ($status === 'rejected') {
                    $summary['rejected']++;
                } elseif (in_array($status, ['submitted', 'under_review'], true)) {
                    $summary['submitted']++;
                } else {
                    $summary['missing']++;
                }
            }
        }
        $summary['complete'] = $summary['required'] > 0 && $summary['verified'] === $summary['required'];

        // Ledger status of each document that was ever verified.
        $this->call->model('LedgerModel');
        foreach ($items as &$item) {
            $item['ledger'] = $item['document_id'] ? $this->LedgerModel->entity_status('student_document', $item['document_id']) : ['state' => 'none', 'blocks' => []];
        }
        unset($item);
        return ['items' => $items, 'summary' => $summary];
    }

    /**
     * Completeness for many students at once (student record lists):
     * [studentId => ['required' => n, 'verified' => n, 'pending' => n, 'rejected' => n]].
     */
    public function completeness($centerId, array $students)
    {
        if (!$students) {
            return [];
        }
        $types = $this->types();
        $rows = $this->db->table('student_documents')
            ->select('student_id, requirement_type_id, status')
            ->where('training_center_id', (int) $centerId)
            ->in('student_id', array_column($students, 'id'))
            ->get_all();
        $status = [];
        foreach ($rows as $row) {
            $status[$row['student_id']][$row['requirement_type_id']] = $row['status'];
        }
        $result = [];
        foreach ($students as $student) {
            $r = ['required' => 0, 'verified' => 0, 'pending' => 0, 'rejected' => 0];
            foreach ($types as $type) {
                if (!(int) $type['is_required'] || !self::applies($type, $student['civil_status'] ?? null)) {
                    continue;
                }
                $r['required']++;
                $s = $status[$student['id']][$type['id']] ?? 'not_submitted';
                if ($s === 'verified') {
                    $r['verified']++;
                } elseif ($s === 'rejected') {
                    $r['rejected']++;
                } elseif ($s === 'submitted' || $s === 'under_review') {
                    $r['pending']++;
                }
            }
            $result[$student['id']] = $r;
        }
        return $result;
    }

    /** Documents waiting for review at a center (submitted or under review). */
    public function pending_count($centerId)
    {
        return (int) $this->db->table('student_documents')
            ->where('training_center_id', (int) $centerId)
            ->in('status', ['submitted', 'under_review'])
            ->count();
    }

    /** The document row with its type, or null. Optionally limited to a center. */
    public function document($docId, $centerId = null)
    {
        $query = $this->db->table('student_documents d')
            ->select('d.id, d.training_center_id, d.student_id, d.requirement_type_id, d.status, d.remarks, d.submitted_at,
                      d.verified_by, d.verified_at, t.name AS type_name, t.required_copies')
            ->inner_join('requirement_types t', 't.id = d.requirement_type_id')
            ->where('d.id', (int) $docId);
        if ($centerId) {
            $query->where('d.training_center_id', (int) $centerId);
        }
        return $query->get() ?: null;
    }

    /** A stored copy with its document's owner and center (for access checks). */
    public function file($fileId)
    {
        return $this->db->table('student_document_files f')
            ->select('f.id, f.student_document_id, f.original_name, f.storage_key, f.mime_type, f.file_size, f.file_hash,
                      f.is_current, f.uploaded_by, f.uploaded_at, d.student_id, d.training_center_id,
                      d.status AS document_status, d.requirement_type_id')
            ->inner_join('student_documents d', 'd.id = f.student_document_id')
            ->where('f.id', (int) $fileId)
            ->get() ?: null;
    }

    /** Current copies of a document. */
    public function current_files($docId)
    {
        return $this->db->table('student_document_files')
            ->where(['student_document_id' => (int) $docId, 'is_current' => 1])
            ->order_by('id', 'ASC')
            ->get_all();
    }

    /** Review history of one document, newest first. */
    public function history($docId)
    {
        return $this->db->table('student_document_reviews r')
            ->select('r.id, r.action, r.from_status, r.to_status, r.remarks, r.acted_at, u.full_name AS acted_by_name, u.role AS acted_by_role')
            ->left_join('users u', 'u.id = r.acted_by')
            ->where('r.student_document_id', (int) $docId)
            ->order_by('r.id', 'DESC')
            ->get_all();
    }

    /** Review history of every document of a student at a center, newest first. */
    public function student_history($centerId, $studentId, $limit = 100)
    {
        return $this->db->table('student_document_reviews r')
            ->select('r.id, r.student_document_id, r.action, r.from_status, r.to_status, r.remarks, r.acted_at,
                      t.name AS type_name, u.full_name AS acted_by_name, u.role AS acted_by_role')
            ->inner_join('student_documents d', 'd.id = r.student_document_id')
            ->inner_join('requirement_types t', 't.id = d.requirement_type_id')
            ->left_join('users u', 'u.id = r.acted_by')
            ->where(['d.training_center_id' => (int) $centerId, 'd.student_id' => (int) $studentId])
            ->order_by('r.id', 'DESC')
            ->limit((int) $limit)
            ->get_all();
    }

    /**
     * Saves new copies for a requirement. Rejected documents are replaced (old
     * copies kept as history); otherwise copies are added up to the required
     * number. Files must already have passed FileStore::problem().
     * Returns the document id. Throws DomainException with a user message.
     */
    public function add_copies($centerId, $studentId, array $type, array $uploads, $userId)
    {
        $existing = $this->db->table($this->table)->where([
            'training_center_id' => (int) $centerId, 'student_id' => (int) $studentId,
            'requirement_type_id' => (int) $type['id'],
        ])->get();
        $status = $existing['status'] ?? 'not_submitted';
        if ($status === 'verified') {
            throw new DomainException("{$type['name']} is already verified. Ask the learning center to reopen it if a copy must change.");
        }
        if ($status === 'under_review') {
            throw new DomainException("{$type['name']} is being reviewed. You can upload again if it is returned.");
        }
        $replacing = $status === 'rejected';
        $current = $existing && !$replacing ? count($this->current_files($existing['id'])) : 0;
        $max = (int) $type['required_copies'];
        if ($current + count($uploads) > $max) {
            $left = max(0, $max - $current);
            throw new DomainException($left
                ? "{$type['name']} needs {$max} " . ($max === 1 ? 'copy' : 'copies') . ". You can add {$left} more."
                : "{$type['name']} already has all {$max} required " . ($max === 1 ? 'copy' : 'copies') . '. Remove one to replace it.');
        }

        $saved = [];
        $this->db->transaction();
        try {
            foreach ($uploads as $upload) {
                $saved[] = FileStore::save('student_documents', $upload);
            }
            $now = date('Y-m-d H:i:s');
            if ($existing) {
                $docId = (int) $existing['id'];
                // A replacement clears the rejection remark; it stays in the review history.
                $update = ['status' => 'submitted', 'submitted_at' => $now, 'uploaded_by' => $userId, 'verified_by' => null, 'verified_at' => null];
                if ($replacing) {
                    $update['remarks'] = null;
                }
                $this->db->table($this->table)->where('id', $docId)->update($update);
            } else {
                $this->db->table($this->table)->insert([
                    'training_center_id' => (int) $centerId, 'student_id' => (int) $studentId,
                    'requirement_type_id' => (int) $type['id'], 'status' => 'submitted',
                    'submitted_at' => $now, 'uploaded_by' => $userId,
                ]);
                $docId = (int) $this->db->last_id();
            }
            if ($replacing) {
                $this->db->table('student_document_files')
                    ->where(['student_document_id' => $docId, 'is_current' => 1])
                    ->update(['is_current' => 0, 'replaced_at' => $now]);
            }
            foreach ($saved as $file) {
                $this->db->table('student_document_files')->insert(array_merge($file, [
                    'student_document_id' => $docId, 'uploaded_by' => $userId, 'uploaded_at' => $now,
                ]));
            }
            $names = implode(', ', array_column($saved, 'original_name'));
            $this->log_review($docId, 'uploaded', $status, 'submitted',
                ($replacing ? 'Replaced rejected copies with: ' : 'Uploaded: ') . $names, $userId);
            $this->db->commit();
            return $docId;
        } catch (Throwable $e) {
            $this->db->roll_back();
            foreach ($saved as $file) {
                FileStore::remove('student_documents', $file['storage_key']);
            }
            throw $e;
        }
    }

    /**
     * Withdraws one copy (kept on record as replaced). Not allowed once the
     * document is under review or verified.
     */
    public function remove_copy(array $file, $userId)
    {
        if (in_array($file['document_status'], ['under_review', 'verified'], true)) {
            throw new DomainException('This document is under review or verified, so its copies cannot be removed.');
        }
        if (!(int) $file['is_current']) {
            throw new DomainException('That copy was already replaced.');
        }
        $this->db->transaction();
        try {
            $this->db->table('student_document_files')->where('id', (int) $file['id'])
                ->update(['is_current' => 0, 'replaced_at' => date('Y-m-d H:i:s')]);
            $left = count($this->current_files($file['student_document_id']));
            $to = $left ? $file['document_status'] : 'not_submitted';
            if ($to !== $file['document_status']) {
                $this->db->table($this->table)->where('id', (int) $file['student_document_id'])->update(['status' => $to]);
            }
            $this->log_review($file['student_document_id'], 'removed', $file['document_status'], $to, 'Removed: ' . $file['original_name'], $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /**
     * Administrator review: action is under_review, verified or rejected.
     * Verification needs all required copies; rejection needs remarks.
     * Verifications are also recorded on the blockchain ledger.
     */
    public function review(array $doc, $action, $remarks, $userId)
    {
        $from = $doc['status'];
        $allowed = [
            'under_review' => ['submitted'],
            'verified' => ['submitted', 'under_review'],
            'rejected' => ['submitted', 'under_review', 'verified'],
        ];
        if (!isset($allowed[$action])) {
            throw new DomainException('Choose start review, verify or reject.');
        }
        if (!in_array($from, $allowed[$action], true)) {
            $labels = ['not_submitted' => 'not submitted', 'submitted' => 'submitted', 'under_review' => 'under review', 'verified' => 'verified', 'rejected' => 'rejected'];
            throw new DomainException("This document is {$labels[$from]}, so it cannot be " . str_replace('_', ' ', $action === 'under_review' ? 'put under review' : $action) . '.');
        }
        $files = $this->current_files($doc['id']);
        if ($action === 'verified' && count($files) < (int) $doc['required_copies']) {
            throw new DomainException(sprintf('%s needs %d %s; %d uploaded.', $doc['type_name'], $doc['required_copies'],
                (int) $doc['required_copies'] === 1 ? 'copy' : 'copies', count($files)));
        }
        if ($action === 'rejected' && trim((string) $remarks) === '') {
            throw new DomainException('Add a remark so the student knows what to fix.');
        }

        $this->db->transaction();
        try {
            $update = ['status' => $action, 'remarks' => $remarks !== '' ? $remarks : ($action === 'verified' ? null : $doc['remarks'])];
            if ($action === 'verified') {
                $update += ['verified_by' => $userId, 'verified_at' => date('Y-m-d H:i:s')];
            } else {
                $update += ['verified_by' => null, 'verified_at' => null];
            }
            $this->db->table($this->table)->where('id', (int) $doc['id'])->update($update);
            $this->log_review($doc['id'], $action, $from, $action, $remarks, $userId);
            if ($action === 'verified') {
                $this->call->model('AuditModel');
                $this->AuditModel->record('student_document', (int) $doc['id'], 'verified', $userId);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** Notes on each verified document that it was reused for a new enrollment. */
    public function record_reuse($centerId, $studentId, $batchName, $userId)
    {
        $verified = $this->db->table($this->table)->select('id')
            ->where(['training_center_id' => (int) $centerId, 'student_id' => (int) $studentId, 'status' => 'verified'])
            ->get_all();
        foreach ($verified as $doc) {
            $this->log_review($doc['id'], 'reused', 'verified', 'verified', "Reused for enrollment in {$batchName}", $userId);
        }
        return count($verified);
    }

    private function log_review($docId, $action, $from, $to, $remarks, $userId)
    {
        $this->db->table('student_document_reviews')->insert([
            'student_document_id' => (int) $docId,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'remarks' => $remarks !== null && $remarks !== '' ? mb_substr((string) $remarks, 0, 500) : null,
            'acted_by' => $userId ?: null,
        ]);
    }
}
