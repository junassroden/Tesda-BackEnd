<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * activity_logs + blockchain_ledger.
 *
 * log()   adds a row to activity_logs (dashboard "Recent activity", audit trail).
 * record() adds a block to blockchain_ledger for an important event; the
 *         block logic and payloads live in app/libraries/Ledger.php and
 *         verification in LedgerModel.
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
     * Records an event as a block on the blockchain ledger (see app/libraries/Ledger.php).
     * The payload is built from the records, so call it after the change is saved,
     * inside the same transaction: the record and its block are saved together.
     * $data is only for events whose record is about to disappear (a deletion).
     */
    public function record($entityType, $entityId, $action, $userId, $data = null)
    {
        $this->call->library('Ledger');
        $this->db->transaction();
        try {
            $hash = Ledger::append(fn ($sql, $params) => $this->db->raw($sql, $params), $entityType, $entityId, $action, $userId, $data);
            $this->db->commit();
            return $hash;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
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
