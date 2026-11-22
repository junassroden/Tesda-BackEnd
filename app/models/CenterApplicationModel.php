<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** center_applications: sign-up requests to join a center. Login uses it to explain inactive accounts. */
class CenterApplicationModel extends Model
{
    protected $table = 'center_applications';
    protected $primary_key = 'id';
    protected $fillable = ['user_id', 'training_center_id', 'applied_role', 'status', 'reviewed_by', 'reviewed_at'];

    /** 'pending', 'approved', 'rejected', or null when the user never applied. */
    public function status_for_user($userId)
    {
        $row = $this->db->table($this->table)
            ->select('status')
            ->where('user_id', (int) $userId)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get();
        return $row['status'] ?? null;
    }
}
