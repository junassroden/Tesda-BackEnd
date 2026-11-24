<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * The blockchain ledger (blockchain_ledger): a private, tamper-evident hash chain.
 *
 * Each block stores only fingerprints:
 *   data_hash   sha256 of the event's payload, built from the records by payload()
 *   block_hash  sha256(index | entity_type | entity_id | action | data_hash | previous_hash)
 * so editing an old record changes its data hash, and editing a block breaks
 * every block after it. No personal data is written to the ledger.
 *
 * The same payload() builds the data when a block is written and again when it
 * is verified, which is how verification proves the records still match what
 * was recorded. Payloads use only facts that never change after the event.
 *
 * Works with any query runner, like BatchLifecycle:
 *   $query(string $sql, array $params): PDOStatement
 * so the API and the CLI write identical blocks. Call append() inside the
 * caller's transaction; it locks the last block so blocks are never forked.
 */
class Ledger
{
    public const ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';
    public const FORMAT = 'v1';
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Appends a block for an event. $data overrides the built payload (only
     * for events whose record is about to disappear, such as a deletion).
     * Returns the new block hash.
     */
    public static function append(callable $query, $entityType, $entityId, $action, $userId, $data = null)
    {
        $previous = $query('SELECT id, block_index, block_hash FROM blockchain_ledger ORDER BY block_index DESC LIMIT 1 FOR UPDATE', [])
            ->fetch(PDO::FETCH_ASSOC);
        $createdAt = date('Y-m-d H:i:s');
        if ($data === null) {
            $built = self::payload($query, [
                'entity_type' => $entityType, 'entity_id' => $entityId, 'action' => $action,
                'payload_format' => self::FORMAT, 'created_at' => $createdAt,
            ]);
            if (!isset($built['data'])) {
                throw new RuntimeException("Ledger: cannot build the payload of {$entityType} #{$entityId} {$action}.");
            }
            $data = $built['data'];
        }
        $index = $previous ? (int) $previous['block_index'] + 1 : 0;
        $previousHash = $previous['block_hash'] ?? self::ZERO_HASH;
        $dataHash = self::data_hash($data);
        $blockHash = self::block_hash($index, $entityType, $entityId, $action, $dataHash, $previousHash);
        $query(
            'INSERT INTO blockchain_ledger (block_index, previous_block_id, entity_type, entity_id, action, data_hash,
                    payload_format, previous_hash, block_hash, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$index, $previous['id'] ?? null, $entityType, $entityId, $action, $dataHash, self::FORMAT,
             $previousHash, $blockHash, $userId ?: null, $createdAt]
        );
        return $blockHash;
    }

    public static function data_hash($data)
    {
        return hash('sha256', is_string($data) ? $data : json_encode($data, self::JSON));
    }

    public static function block_hash($index, $entityType, $entityId, $action, $dataHash, $previousHash)
    {
        return hash('sha256', implode('|', [(int) $index, $entityType, $entityId === null ? '' : (string) $entityId, $action, $dataHash, $previousHash]));
    }

    /**
     * Rebuilds a block's payload from the records.
     * Returns one of:
     *   ['data' => mixed]          the payload (hash it with data_hash())
     *   ['missing' => true]        the record no longer exists
     *   ['unverifiable' => reason] the content cannot be rebuilt (seed or deletion blocks)
     */
    public static function payload(callable $query, array $block)
    {
        $type = $block['entity_type'];
        $id = (int) $block['entity_id'];
        $action = $block['action'];
        $format = $block['payload_format'] ?? self::FORMAT;
        $one = fn ($sql, $params) => $query($sql, $params)->fetch(PDO::FETCH_ASSOC) ?: null;
        $all = fn ($sql, $params) => $query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        if ($format === 'genesis') {
            return ['data' => 'TESDA NEXUS genesis block'];
        }
        if ($format === 'seed') {
            return ['unverifiable' => 'Demo block from the database seed. Its fingerprint was made from a hand-written string, so only its place in the chain can be checked.'];
        }
        if ($action === 'deleted') {
            return ['unverifiable' => 'Records the deletion of a record, so there is nothing left to rebuild.'];
        }

        switch ("{$type}.{$action}") {
            /* ----- Inventory requests ----- */
            case 'inventory_request.released': // v0: the main office's old direct release
                $r = $one('SELECT request_code, training_center_id FROM inventory_requests WHERE id = ?', [$id]);
                if (!$r) {
                    return ['missing' => true];
                }
                $items = $all('SELECT i.product_id, i.quantity_requested, i.quantity_approved FROM inventory_request_items i
                                INNER JOIN products p ON p.id = i.product_id WHERE i.request_id = ? ORDER BY p.product_name ASC', [$id]);
                return ['data' => [
                    'request_code' => $r['request_code'],
                    'training_center_id' => (int) $r['training_center_id'],
                    'items' => array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (int) ($i['quantity_approved'] ?? $i['quantity_requested'])], $items),
                ]];

            case 'inventory_request.submitted':
                $r = $one('SELECT request_code, training_center_id, requested_by, submitted_at, letter_mode, letter_date, letter_recipient,
                                  letter_subject, letter_body, letter_signatory, letter_position
                             FROM inventory_requests WHERE id = ?', [$id]);
                if (!$r) {
                    return ['missing' => true];
                }
                $items = $all('SELECT product_id, quantity_requested, justification FROM inventory_request_items WHERE request_id = ? ORDER BY product_id', [$id]);
                $files = $all('SELECT kind, file_hash FROM inventory_request_files WHERE request_id = ? ORDER BY id', [$id]);
                return ['data' => [
                    'request_code' => $r['request_code'],
                    'training_center_id' => (int) $r['training_center_id'],
                    'requested_by' => $r['requested_by'] === null ? null : (int) $r['requested_by'],
                    'submitted_at' => $r['submitted_at'],
                    'letter' => [
                        'mode' => $r['letter_mode'],
                        'date' => $r['letter_date'],
                        'recipient' => $r['letter_recipient'],
                        'subject' => $r['letter_subject'],
                        'body_sha256' => hash('sha256', (string) $r['letter_body']),
                        'signatory' => $r['letter_signatory'],
                        'position' => $r['letter_position'],
                    ],
                    'files' => array_map(fn ($f) => ['kind' => $f['kind'], 'sha256' => $f['file_hash']], $files),
                    'items' => array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (int) $i['quantity_requested'], 'justification' => $i['justification']], $items),
                ]];

            case 'inventory_request.approved':
            case 'inventory_request.rejected':
                $r = $one('SELECT request_code, reviewed_by, reviewed_at, admin_remarks FROM inventory_requests WHERE id = ?', [$id]);
                if (!$r) {
                    return ['missing' => true];
                }
                $items = $action === 'approved'
                    ? $all('SELECT product_id, quantity_approved FROM inventory_request_items WHERE request_id = ? ORDER BY product_id', [$id])
                    : [];
                return ['data' => [
                    'request_code' => $r['request_code'],
                    'decision' => $action,
                    'reviewed_by' => $r['reviewed_by'] === null ? null : (int) $r['reviewed_by'],
                    'reviewed_at' => $r['reviewed_at'],
                    'remarks' => $r['admin_remarks'],
                    'approved_items' => array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => $i['quantity_approved'] === null ? null : (int) $i['quantity_approved']], $items),
                ]];

            case 'inventory_request.received':
            case 'inventory_request.discrepancy':
                $r = $one('SELECT request_code, received_by, received_at, receipt_notes FROM inventory_requests WHERE id = ?', [$id]);
                if (!$r) {
                    return ['missing' => true];
                }
                $items = $all('SELECT product_id, quantity_received FROM inventory_request_items WHERE request_id = ? ORDER BY product_id', [$id]);
                return ['data' => [
                    'request_code' => $r['request_code'],
                    'outcome' => $action,
                    'received_by' => $r['received_by'] === null ? null : (int) $r['received_by'],
                    'received_at' => $r['received_at'],
                    'notes' => $r['receipt_notes'],
                    'received_items' => array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => $i['quantity_received'] === null ? null : (int) $i['quantity_received']], $items),
                ]];

            case 'inventory_request.resolved':
                $r = $one('SELECT request_code, resolved_by, resolved_at, resolution_notes FROM inventory_requests WHERE id = ?', [$id]);
                return $r ? ['data' => [
                    'request_code' => $r['request_code'],
                    'resolved_by' => $r['resolved_by'] === null ? null : (int) $r['resolved_by'],
                    'resolved_at' => $r['resolved_at'],
                    'notes' => $r['resolution_notes'],
                ]] : ['missing' => true];

            /* ----- QR deliveries ----- */
            case 'inventory_delivery.generated':
                $d = $one('SELECT d.delivery_code, d.qr_token, d.prepared_by, d.prepared_at, r.request_code
                             FROM inventory_deliveries d INNER JOIN inventory_requests r ON r.id = d.request_id WHERE d.id = ?', [$id]);
                if (!$d) {
                    return ['missing' => true];
                }
                return ['data' => [
                    'delivery_code' => $d['delivery_code'],
                    'request_code' => $d['request_code'],
                    'qr_token_sha256' => hash('sha256', $d['qr_token']),
                    'prepared_by' => $d['prepared_by'] === null ? null : (int) $d['prepared_by'],
                    'prepared_at' => $d['prepared_at'],
                    'items' => self::delivery_items($all, $id, 'p.id'),
                ]];

            case 'inventory_delivery.released':
                $d = $one('SELECT d.delivery_code, d.released_by, d.released_at, r.request_code, r.training_center_id
                             FROM inventory_deliveries d INNER JOIN inventory_requests r ON r.id = d.request_id WHERE d.id = ?', [$id]);
                if (!$d) {
                    return ['missing' => true];
                }
                return ['data' => [
                    'delivery_code' => $d['delivery_code'],
                    'request_code' => $d['request_code'],
                    'training_center_id' => (int) $d['training_center_id'],
                    // v0 blocks listed items in product-name order; v1 uses product ids (renames cannot affect it).
                    'items' => self::delivery_items($all, $id, $format === 'v0' ? 'p.product_name' : 'p.id'),
                    'released_by' => $d['released_by'] === null ? null : (int) $d['released_by'],
                    'released_at' => $d['released_at'],
                ]];

            case 'inventory_delivery.delivered':
                $d = $one('SELECT delivery_code, delivered_by, delivered_at FROM inventory_deliveries WHERE id = ?', [$id]);
                return $d ? ['data' => [
                    'delivery_code' => $d['delivery_code'],
                    'delivered_by' => $d['delivered_by'] === null ? null : (int) $d['delivered_by'],
                    'delivered_at' => $d['delivered_at'],
                ]] : ['missing' => true];

            /* ----- Allowance ----- */
            case 'allowance_request.released':
                $r = $one('SELECT request_code, training_center_id, batch_id FROM allowance_requests WHERE id = ?', [$id]);
                if (!$r) {
                    return ['missing' => true];
                }
                $rows = $all('SELECT student_id, amount, bank_account_number FROM allowance_request_students WHERE allowance_request_id = ? ORDER BY student_id ASC', [$id]);
                return ['data' => [
                    'request_code' => $r['request_code'],
                    'training_center_id' => (int) $r['training_center_id'],
                    'batch_id' => (int) $r['batch_id'],
                    'students' => array_map(fn ($s) => ['student_id' => (int) $s['student_id'], 'amount' => $s['amount'], 'account' => $s['bank_account_number']], $rows),
                ]];

            /* ----- Student documents ----- */
            case 'student_document.verified':
                $doc = $one('SELECT student_id, requirement_type_id FROM student_documents WHERE id = ?', [$id]);
                if (!$doc) {
                    return ['missing' => true];
                }
                // The copies that were current when the block was written (later replacements are kept, not deleted).
                $files = $all('SELECT file_hash FROM student_document_files
                                WHERE student_document_id = ? AND uploaded_at <= ? AND (replaced_at IS NULL OR replaced_at > ?)
                                ORDER BY id ASC', [$id, $block['created_at'], $block['created_at']]);
                return ['data' => [
                    'student_id' => (int) $doc['student_id'],
                    'requirement_type_id' => (int) $doc['requirement_type_id'],
                    'files' => array_column($files, 'file_hash'),
                ]];

            /* ----- Training batches ----- */
            case 'batch.completed':
                $b = $one('SELECT b.id, b.course_id, b.batch_name, b.completion_mode, b.completed_by, b.completed_at,
                                  COALESCE(s.held_hours, 0) AS held_hours, COALESCE(s.sessions, 0) AS sessions
                             FROM batches b
                             LEFT JOIN (SELECT batch_id, SUM(hours) AS held_hours, COUNT(*) AS sessions
                                          FROM batch_sessions WHERE status = \'held\' GROUP BY batch_id) s ON s.batch_id = b.id
                            WHERE b.id = ?', [$id]);
                if (!$b) {
                    return ['missing' => true];
                }
                $done = $all("SELECT student_id FROM batch_students WHERE batch_id = ? AND status = 'completed' ORDER BY student_id", [$id]);
                return ['data' => [
                    'batch_id' => (int) $b['id'],
                    'course_id' => (int) $b['course_id'],
                    'batch_name' => $b['batch_name'],
                    'completion_mode' => $b['completion_mode'],
                    'completed_by' => $b['completed_by'] === null ? null : (int) $b['completed_by'],
                    'completed_at' => $b['completed_at'],
                    'held_hours' => number_format((float) $b['held_hours'], 2, '.', ''),
                    'sessions_held' => (int) $b['sessions'],
                    'completed_students' => array_map('intval', array_column($done, 'student_id')),
                ]];

            /* ----- QR-verifiable printed documents ----- */
            case 'seal.issued':
                $s = $one('SELECT id, kind, entity_id, training_center_id, snapshot_hash, token, issued_by, issued_at FROM document_seals WHERE id = ?', [$id]);
                return $s ? ['data' => [
                    'seal_id' => (int) $s['id'],
                    'kind' => $s['kind'],
                    'entity_id' => (int) $s['entity_id'],
                    'training_center_id' => $s['training_center_id'] === null ? null : (int) $s['training_center_id'],
                    'snapshot_sha256' => $s['snapshot_hash'],
                    'token_sha256' => hash('sha256', $s['token']),
                    'issued_by' => $s['issued_by'] === null ? null : (int) $s['issued_by'],
                    'issued_at' => $s['issued_at'],
                ]] : ['missing' => true];

            /* ----- Main office document archive ----- */
            case 'document.archived':
                $d = $one('SELECT id, training_center_id, uploaded_by, file_hash, file_size, mime_type, created_at FROM document_archive WHERE id = ?', [$id]);
                return $d ? ['data' => [
                    'document_id' => (int) $d['id'],
                    'training_center_id' => (int) $d['training_center_id'],
                    'uploaded_by' => $d['uploaded_by'] === null ? null : (int) $d['uploaded_by'],
                    'sha256' => $d['file_hash'],
                    'file_size' => (int) $d['file_size'],
                    'mime_type' => $d['mime_type'],
                    'archived_at' => $d['created_at'],
                ]] : ['missing' => true];
        }
        return ['unverifiable' => "No rebuild rule for {$type} {$action}."];
    }

    private static function delivery_items(callable $all, $deliveryId, $orderBy)
    {
        $items = $all("SELECT i.product_id, i.quantity FROM inventory_delivery_items i
                        INNER JOIN products p ON p.id = i.product_id WHERE i.delivery_id = ? ORDER BY {$orderBy} ASC", [$deliveryId]);
        return array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (int) $i['quantity']], $items);
    }
}
