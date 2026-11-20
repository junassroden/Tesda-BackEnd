<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** users: every account (admin, center_admin, instructor, student). */
class UserModel extends Model
{
    protected $table = 'users';
    protected $primary_key = 'id';
    protected $fillable = [
        'username', 'full_name', 'email', 'password', 'role',
        'training_center_id', 'contact_number', 'is_active', 'last_login_at',
    ];

    /** Login by email or username. */
    public function find_by_identity($identity)
    {
        return $this->db->table($this->table)
            ->where('email', $identity)
            ->or_where('username', $identity)
            ->get();
    }

    /** The user plus their center's code, for the document archive access check. */
    public function with_center($userId)
    {
        return $this->db->table('users u')
            ->select('u.id, u.role, u.is_active, tc.center_code, tc.is_active AS center_is_active')
            ->left_join('training_centers tc', 'tc.id = u.training_center_id')
            ->where('u.id', $userId)
            ->get();
    }

    /** Active students of one center. */
    public function students($centerId)
    {
        return $this->db->table($this->table)
            ->select('id, full_name, username, email')
            ->where(['role' => 'student', 'is_active' => 1, 'training_center_id' => $centerId])
            ->order_by('full_name', 'ASC')
            ->get_all();
    }

    /** Is the email used by another account? */
    public function email_taken($email, $exceptId = 0)
    {
        return (bool) $this->db->table($this->table)
            ->where('email', $email)
            ->where('id', '!=', $exceptId)
            ->get();
    }

    public function record_login($userId)
    {
        return $this->update($userId, ['last_login_at' => date('Y-m-d H:i:s')]);
    }
}
