<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Reading and verifying the blockchain ledger (blockchain_ledger +
 * blockchain_verifications). Blocks are written by AuditModel::record().
 *
 * Block statuses:
 *   verified      the block is intact, linked to the block before it, and the
 *                 payload rebuilt from today's records has the recorded fingerprint
 *   tampered      the block itself, its link, its records or its files changed
 *   missing       the record is gone and no deletion was recorded
 *   removed       the record is gone and its deletion is on the ledger
 *   unverifiable  intact and linked, but its content cannot be rebuilt (seed blocks)
 */
class LedgerModel extends Model
{
    protected $table = 'blockchain_ledger';
    protected $primary_key = 'id';

    private const COLUMNS = 'id, block_index, previous_block_id, entity_type, entity_id, action, data_hash, payload_format,
                             previous_hash, block_hash, network, tx_hash, created_by, created_at';

    public function __construct()
    {
        parent::__construct();
        $this->call->library('Ledger');
        $this->call->library('FileStore');
    }

    private function q()
    {
        return fn ($sql, $params = []) => $this->db->raw($sql, $params);
    }

    /** Blocks newest first. filters: entity_type, entity_id, q (hash prefix). Returns [rows, total]. */
    public function listing(array $filters, $page = 1, $perPage = 25)
    {
        $where = [];
        $params = [];
        if (!empty($filters['entity_type'])) {
            $where[] = 'l.entity_type = ?';
            $params[] = $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $where[] = 'l.entity_id = ?';
            $params[] = (int) $filters['entity_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(l.block_hash LIKE ? OR l.data_hash LIKE ?)';
            $params[] = strtolower($filters['q']) . '%';
            $params[] = strtolower($filters['q']) . '%';
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->db->raw("SELECT COUNT(*) AS n FROM blockchain_ledger l {$sqlWhere}", $params)->fetch(PDO::FETCH_ASSOC)['n'];
        $offset = max(0, ((int) $page - 1) * (int) $perPage);
        $rows = $this->db->raw(
            "SELECT l.id, l.block_index, l.previous_block_id, l.entity_type, l.entity_id, l.action, l.data_hash, l.payload_format,
                    l.previous_hash, l.block_hash, l.network, l.tx_hash, l.created_by, l.created_at, u.full_name AS created_by_name,
                    v.is_valid AS last_valid, v.verified_at AS last_verified_at
               FROM blockchain_ledger l
               LEFT JOIN users u ON u.id = l.created_by
               LEFT JOIN blockchain_verifications v ON v.id = (SELECT MAX(id) FROM blockchain_verifications WHERE ledger_id = l.id)
               {$sqlWhere}
              ORDER BY l.block_index DESC
              LIMIT " . (int) $perPage . ' OFFSET ' . $offset,
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
        return [$this->label($rows), $total];
    }

    public function find_block($id)
    {
        $row = $this->db->raw('SELECT ' . self::COLUMNS . ' FROM blockchain_ledger WHERE id = ?', [(int) $id])->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->label([$row])[0] : null;
    }

    /** Anchors newest first. */
    public function anchors($limit = 20)
    {
        return $this->db->raw(
            'SELECT a.id, a.ledger_id, a.block_index, a.block_hash, a.network, a.method, a.status, a.bitcoin_height, a.bitcoin_block,
                    a.bitcoin_time, a.submitted_at, a.confirmed_at, a.last_checked_at, a.last_error, u.full_name AS submitted_by_name,
                    JSON_LENGTH(a.proofs) AS calendars
               FROM blockchain_anchors a LEFT JOIN users u ON u.id = a.submitted_by
              ORDER BY a.id DESC LIMIT ' . (int) $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find_anchor($id)
    {
        return $this->db->raw('SELECT * FROM blockchain_anchors WHERE id = ?', [(int) $id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Highest block index covered by a confirmed anchor, and by any anchor. */
    public function anchored_upto()
    {
        $row = $this->db->raw(
            "SELECT MAX(CASE WHEN status = 'confirmed' THEN block_index END) AS confirmed, MAX(block_index) AS submitted FROM blockchain_anchors"
        )->fetch(PDO::FETCH_ASSOC);
        return ['confirmed' => $row['confirmed'] === null ? null : (int) $row['confirmed'], 'submitted' => $row['submitted'] === null ? null : (int) $row['submitted']];
    }

    /** Totals for the ledger page. */
    public function stats()
    {
        $row = $this->db->raw(
            'SELECT COUNT(*) AS blocks, MAX(created_at) AS last_block_at,
                    (SELECT COUNT(*) FROM blockchain_verifications) AS verifications,
                    (SELECT MAX(verified_at) FROM blockchain_verifications) AS last_verified_at
               FROM blockchain_ledger'
        )->fetch(PDO::FETCH_ASSOC);
        $types = $this->db->raw('SELECT entity_type, COUNT(*) AS n FROM blockchain_ledger GROUP BY entity_type')->fetchAll(PDO::FETCH_ASSOC);
        $row['by_type'] = array_map('intval', array_column($types, 'n', 'entity_type'));
        $row['blocks'] = (int) $row['blocks'];
        $row['verifications'] = (int) $row['verifications'];
        return $row;
    }

    /**
     * Walks the whole chain from the genesis block: every index in order, every
     * link to the previous block, and every block hash recomputed. Stops at the
     * first broken block (everything after it is untrusted).
     */
    public function verify_chain()
    {
        $blocks = $this->db->raw('SELECT ' . self::COLUMNS . ' FROM blockchain_ledger ORDER BY block_index ASC')->fetchAll(PDO::FETCH_ASSOC);
        $previous = null;
        foreach ($blocks as $i => $block) {
            $problem = $this->structure_problem($block, $previous, $i);
            if ($problem) {
                return ['valid' => false, 'blocks_checked' => $i, 'total' => count($blocks),
                        'first_broken' => $this->label([$block])[0], 'reason' => $problem];
            }
            $previous = $block;
        }
        return ['valid' => true, 'blocks_checked' => count($blocks), 'total' => count($blocks),
                'last_hash' => $previous['block_hash'] ?? null];
    }

    /**
     * Fully verifies one block: its own hash, its link, the payload rebuilt from
     * the records, and (for files) the SHA-256 of the stored file itself.
     * Saves the result in blockchain_verifications when $userId is given.
     */
    public function verify_block(array $block, $userId = null, $checkFiles = true)
    {
        $checks = [];
        $previous = $block['previous_block_id']
            ? $this->db->raw('SELECT ' . self::COLUMNS . ' FROM blockchain_ledger WHERE id = ?', [(int) $block['previous_block_id']])->fetch(PDO::FETCH_ASSOC)
            : null;
        $structure = $this->structure_problem($block, $previous, (int) $block['block_index']);
        $checks[] = ['label' => 'Block hash and link to the previous block', 'ok' => !$structure, 'detail' => $structure ?: 'The block is unchanged and still points to the block before it.'];

        $built = Ledger::payload($this->q(), $block);
        $recomputed = null;
        $payload = null;
        if (isset($built['data'])) {
            $payload = $built['data'];
            $recomputed = Ledger::data_hash($payload);
            $match = hash_equals($block['data_hash'], $recomputed);
            $checks[] = ['label' => 'Records match the recorded fingerprint', 'ok' => $match,
                         'detail' => $match ? 'The data rebuilt from the records has the same SHA-256 fingerprint.' : 'The records no longer produce the recorded fingerprint: they were changed after this event.'];
            if ($checkFiles) {
                foreach ($this->file_checks($block, $payload) as $check) {
                    $checks[] = $check;
                }
            }
            $status = array_filter($checks, fn ($c) => $c['ok'] === false) ? 'tampered' : 'verified';
        } elseif (isset($built['missing'])) {
            $deleted = $this->db->raw("SELECT id FROM blockchain_ledger WHERE entity_type = ? AND entity_id = ? AND action = 'deleted' LIMIT 1",
                [$block['entity_type'], (int) $block['entity_id']])->fetch(PDO::FETCH_ASSOC);
            $status = $structure ? 'tampered' : ($deleted ? 'removed' : 'missing');
            $checks[] = ['label' => 'Record exists', 'ok' => (bool) $deleted,
                         'detail' => $deleted ? 'The record was deleted later, and the deletion is recorded on the ledger.' : 'The record is gone and no deletion was recorded.'];
        } else {
            $status = $structure ? 'tampered' : 'unverifiable';
            $checks[] = ['label' => 'Records', 'ok' => null, 'detail' => $built['unverifiable']];
        }

        if ($userId) {
            $this->db->table('blockchain_verifications')->insert([
                'ledger_id' => (int) $block['id'],
                'recomputed_hash' => $recomputed ?? Ledger::block_hash($block['block_index'], $block['entity_type'], $block['entity_id'], $block['action'], $block['data_hash'], $block['previous_hash']),
                'is_valid' => in_array($status, ['verified', 'removed', 'unverifiable'], true) ? 1 : 0,
                'remarks' => mb_substr(ucfirst($status) . ': ' . implode(' ', array_map(fn ($c) => $c['detail'], array_filter($checks, fn ($c) => $c['ok'] !== true))), 0, 255),
                'verified_by' => $userId,
            ]);
        }
        return [
            'status' => $status,
            'checks' => $checks,
            'recomputed_hash' => $recomputed,
            'payload' => $payload,
        ];
    }

    /**
     * The "Verified on ledger" badge of a record: its blocks checked against the
     * records (no file hashing and nothing saved, so it is cheap enough for every page).
     * $related adds other entities' blocks, e.g. a request's delivery.
     */
    public function entity_status($entityType, $entityId, array $related = [])
    {
        $pairs = array_merge([[$entityType, (int) $entityId]], $related);
        $blocks = [];
        foreach ($pairs as [$type, $id]) {
            if (!$id) {
                continue;
            }
            $rows = $this->db->raw('SELECT ' . self::COLUMNS . ' FROM blockchain_ledger WHERE entity_type = ? AND entity_id = ? ORDER BY block_index', [$type, (int) $id])
                ->fetchAll(PDO::FETCH_ASSOC);
            $blocks = array_merge($blocks, $rows);
        }
        if (!$blocks) {
            return ['state' => 'none', 'blocks' => []];
        }
        usort($blocks, fn ($a, $b) => $a['block_index'] <=> $b['block_index']);
        $out = [];
        $bad = false;
        foreach ($blocks as $block) {
            $result = $this->verify_block($block, null, false);
            $bad = $bad || in_array($result['status'], ['tampered', 'missing'], true);
            $out[] = ['id' => (int) $block['id'], 'block_index' => (int) $block['block_index'], 'entity_type' => $block['entity_type'],
                      'action' => $block['action'], 'created_at' => $block['created_at'], 'status' => $result['status'],
                      'block_hash' => $block['block_hash']];
        }
        return ['state' => $bad ? 'mismatch' : 'verified', 'blocks' => $out];
    }

    /* ---------------------------------------------------------------- */

    /** Why a block is not intact or not linked (null when it is). */
    private function structure_problem(array $block, $previous, $expectedIndex)
    {
        if ((int) $block['block_index'] !== (int) $expectedIndex) {
            return "Expected block #{$expectedIndex} here but found #{$block['block_index']}: a block is missing or was inserted.";
        }
        $expectedPrev = $previous['block_hash'] ?? Ledger::ZERO_HASH;
        if ($previous === null && (int) $block['block_index'] !== 0) {
            return 'The block before it is missing.';
        }
        if (!hash_equals($expectedPrev, (string) $block['previous_hash'])) {
            return 'Its "previous hash" does not match the block before it: the chain was altered here.';
        }
        if ($previous && (int) $block['previous_block_id'] !== (int) $previous['id']) {
            return 'It points to the wrong previous block.';
        }
        $recomputed = Ledger::block_hash($block['block_index'], $block['entity_type'], $block['entity_id'], $block['action'], $block['data_hash'], $block['previous_hash']);
        if (!hash_equals($recomputed, (string) $block['block_hash'])) {
            return 'Its block hash no longer matches its contents: the block itself was edited.';
        }
        return null;
    }

    /** Re-hashes the stored files a payload refers to. */
    private function file_checks(array $block, $payload)
    {
        $checks = [];
        if ($block['entity_type'] === 'document' && $block['action'] === 'archived') {
            $doc = $this->db->raw('SELECT storage_key FROM document_archive WHERE id = ?', [(int) $block['entity_id']])->fetch(PDO::FETCH_ASSOC);
            $path = $doc ? config_item('storage_dir') . 'document-archive' . DIRECTORY_SEPARATOR . basename($doc['storage_key']) : null;
            $checks[] = $this->file_check('Archived file', $path, $payload['sha256'] ?? null);
        }
        if ($block['entity_type'] === 'student_document' && $block['action'] === 'verified') {
            foreach ($payload['files'] as $n => $hash) {
                $f = $this->db->raw('SELECT storage_key FROM student_document_files WHERE student_document_id = ? AND file_hash = ? LIMIT 1',
                    [(int) $block['entity_id'], $hash])->fetch(PDO::FETCH_ASSOC);
                $checks[] = $this->file_check('Copy ' . ($n + 1), $f ? FileStore::path('student_documents', $f['storage_key']) : null, $hash);
            }
        }
        if ($block['entity_type'] === 'inventory_request' && $block['action'] === 'submitted') {
            $files = $this->db->raw('SELECT kind, original_name, storage_key, file_hash FROM inventory_request_files WHERE request_id = ? ORDER BY id', [(int) $block['entity_id']])
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($files as $f) {
                $profile = $f['kind'] === 'letter' ? 'inventory_letter' : 'inventory_photo';
                $checks[] = $this->file_check(($f['kind'] === 'letter' ? 'Letter' : 'Photo') . ' ' . $f['original_name'], FileStore::path($profile, $f['storage_key']), $f['file_hash']);
            }
        }
        return $checks;
    }

    private function file_check($label, $path, $expected)
    {
        if (!$expected) {
            return ['label' => $label, 'ok' => null, 'detail' => 'No fingerprint was recorded for this file.'];
        }
        if (!$path || !is_file($path)) {
            return ['label' => $label, 'ok' => false, 'detail' => 'The stored file is missing.'];
        }
        $ok = hash_equals($expected, hash_file('sha256', $path));
        return ['label' => $label, 'ok' => $ok, 'detail' => $ok ? 'The stored file has the recorded SHA-256 fingerprint.' : 'The stored file was replaced or edited.'];
    }

    /** Adds a readable label and a link target to each block. */
    private function label(array $rows)
    {
        $ids = [];
        foreach ($rows as $r) {
            if ($r['entity_id']) {
                $ids[$r['entity_type']][] = (int) $r['entity_id'];
            }
        }
        $lookups = [
            'inventory_request' => 'SELECT id, request_code AS label FROM inventory_requests',
            'inventory_delivery' => 'SELECT d.id, CONCAT(d.delivery_code, \' · \', r.request_code) AS label, d.request_id AS ref FROM inventory_deliveries d INNER JOIN inventory_requests r ON r.id = d.request_id',
            'allowance_request' => 'SELECT id, request_code AS label FROM allowance_requests',
            'batch' => 'SELECT b.id, CONCAT(b.batch_name, \' · \', c.course_code) AS label FROM batches b INNER JOIN courses c ON c.id = b.course_id',
            'document' => 'SELECT id, original_name AS label FROM document_archive',
            'student_document' => 'SELECT d.id, CONCAT(t.name, \' · \', tc.name) AS label FROM student_documents d INNER JOIN requirement_types t ON t.id = d.requirement_type_id INNER JOIN training_centers tc ON tc.id = d.training_center_id',
            'seal' => 'SELECT id, CONCAT(\'Printed \', REPLACE(kind, \'_\', \' \'), \' #\', entity_id) AS label FROM document_seals',
        ];
        $found = [];
        foreach ($ids as $type => $list) {
            if (!isset($lookups[$type])) {
                continue;
            }
            $alias = in_array($type, ['inventory_delivery', 'batch', 'student_document'], true) ? ($type === 'batch' ? 'b' : 'd') . '.id' : 'id';
            $in = implode(',', array_unique($list));
            foreach ($this->db->raw("{$lookups[$type]} WHERE {$alias} IN ({$in})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[$type][$row['id']] = $row;
            }
        }
        foreach ($rows as &$r) {
            $hit = $found[$r['entity_type']][$r['entity_id']] ?? null;
            $r['entity_label'] = $r['entity_type'] === 'system' ? 'Genesis block' : ($hit['label'] ?? "#{$r['entity_id']} (no longer exists)");
            $r['entity_ref'] = $hit['ref'] ?? null;
        }
        return $rows;
    }
}
