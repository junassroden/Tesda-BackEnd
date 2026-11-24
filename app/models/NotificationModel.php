<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** notifications: status updates shown in each user's top bar. */
class NotificationModel extends Model
{
    protected $table = 'notifications';
    protected $primary_key = 'id';
    protected $fillable = ['user_id', 'title', 'message', 'entity_type', 'entity_id', 'is_read'];

    /** Sends one notification to each user id (duplicates and empties skipped). */
    public function notify(array $userIds, $title, $message, $entityType = null, $entityId = null)
    {
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $userId) {
            $this->db->table($this->table)->insert([
                'user_id' => $userId,
                'title' => mb_substr($title, 0, 150),
                'message' => mb_substr($message, 0, 500),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);
        }
    }

    /** Active users with a role, optionally only at one center. */
    public function user_ids($role, $centerId = null)
    {
        $query = $this->db->table('users')->select('id')->where(['role' => $role, 'is_active' => 1]);
        if ($centerId) {
            $query->where('training_center_id', (int) $centerId);
        }
        return array_map('intval', array_column($query->get_all(), 'id'));
    }

    /** Newest first. */
    public function for_user($userId, $limit = 30)
    {
        return $this->db->table($this->table)
            ->select('id, title, message, entity_type, entity_id, is_read, created_at')
            ->where('user_id', (int) $userId)
            ->order_by('id', 'DESC')
            ->limit((int) $limit)
            ->get_all();
    }

    public function unread_count($userId)
    {
        return (int) $this->db->table($this->table)->where(['user_id' => (int) $userId, 'is_read' => 0])->count();
    }

    /** Marks one notification (or all when $id is null) of the user as read. */
    public function mark_read($userId, $id = null)
    {
        $query = $this->db->table($this->table)->where('user_id', (int) $userId);
        if ($id) {
            $query->where('id', (int) $id);
        }
        return $query->update(['is_read' => 1]);
    }
}
