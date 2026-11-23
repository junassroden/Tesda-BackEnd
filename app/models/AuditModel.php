<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * activity_logs + blockchain_ledger.
 *
 * log()   adds a row to activity_logs (dashboard "Recent activity", audit trail).
 * chain() adds a block to blockchain_ledger. Each block stores the SHA-256 of
 *         the record's data and the previous block's hash, so editing an old
 *         record breaks every block after it. Block hash formula (matches the
 *         genesis block in database/tesda_nexus.sql):
 *         sha256(index | entity_type | entity_id | action | data_hash | previous_hash)
 */
class AuditModel extends Model
{
    protected $table = 'activity_logs';
    protected $primary_key = 'id';
    protected $fillable = ['user_id', 'training_center_id', 'action', 'entity_type', 'entity_id', 'description', 'ip_address'];

    public function log($userId, $centerId, $action, $entityType, $entityId, $description)
    {
        return $this->insert([
            'user_id' => $userId ?: null,
            'training_center_id' => $centerId ?: null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => mb_substr($description, 0, 500),
            'ip_address' => $this->io->ip_address(),
        ]);
    }

    /**
     * Append a block. Call it inside the caller's transaction so the record and
     * its block are saved together. block_index is unique, so two blocks can
     * never claim the same position.
     */
    public function chain($entityType, $entityId, $action, array $data, $userId)
    {
        $previous = $this->db->table('blockchain_ledger')
            ->select('id, block_index, block_hash')
            ->order_by('block_index', 'DESC')
            ->limit(1)
            ->get();

        $index = $previous ? (int) $previous['block_index'] + 1 : 0;
        $previousHash = $previous['block_hash'] ?? str_repeat('0', 64);
        $dataHash = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $blockHash = hash('sha256', implode('|', [$index, $entityType, (string) $entityId, $action, $dataHash, $previousHash]));

        $this->db->table('blockchain_ledger')->insert([
            'block_index' => $index,
            'previous_block_id' => $previous['id'] ?? null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'data_hash' => $dataHash,
            'previous_hash' => $previousHash,
            'block_hash' => $blockHash,
            'created_by' => $userId ?: null,
        ]);
        return $blockHash;
    }

    /** The audit trail of one record (e.g. a batch's status changes), newest first. */
    public function for_entity($entityType, $entityId, $limit = 50)
    {
        return $this->db->table('activity_logs a')
            ->select('a.id, a.action, a.description, a.created_at, u.full_name AS user_name')
            ->left_join('users u', 'u.id = a.user_id')
            ->where(['a.entity_type' => $entityType, 'a.entity_id' => (int) $entityId])
            ->order_by('a.id', 'DESC')
            ->limit((int) $limit)
            ->get_all();
    }

    /** Recent activity for the dashboard: everything, or one center's. */
    public function recent($centerId = null, $limit = 6)
    {
        $query = $this->db->table('activity_logs a')
            ->select('a.id, a.action, a.entity_type, a.entity_id, a.description, a.created_at,
                      u.full_name AS user_name, tc.name AS center_name')
            ->left_join('users u', 'u.id = a.user_id')
            ->left_join('training_centers tc', 'tc.id = a.training_center_id');
        if ($centerId) {
            $query->where('a.training_center_id', (int) $centerId);
        }
        return $query->order_by('a.id', 'DESC')->limit((int) $limit)->get_all();
    }
}
