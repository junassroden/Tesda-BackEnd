<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Main office staff accounts: inventory personnel and delivery personnel.
 * Main office administrators create them and switch them on or off.
 * They belong to the TESDA main office (center_code TESDA-MAIN).
 */
class StaffController extends MY_Controller
{
    private const ROLES = ['inventory_staff' => 'Inventory personnel', 'delivery_staff' => 'Delivery personnel'];

    public function __construct()
    {
        parent::__construct();
        $this->require_role('admin', 'Only main office administrators manage staff accounts.');
        $this->call->model('UserModel');
        $this->call->model('AuditModel');
    }

    /** GET /staff */
    public function index()
    {
        $staff = $this->db->table('users')
            ->select('id, username, full_name, email, contact_number, role, is_active, last_login_at, created_at')
            ->in('role', array_keys(self::ROLES))
            ->order_by('full_name', 'ASC')
            ->get_all();
        $this->json(['staff' => $staff]);
    }

    /** POST /staff  { full_name, email, contact_number, role, password } */
    public function create()
    {
        $this->validate([
            'full_name|Full name' => 'required|max_length[150]',
            'email|Email' => 'required|valid_email|max_length[255]',
            'contact_number|Contact number' => 'max_length[30]',
            'password|Initial password' => 'required|min_length[8]|max_length[72]',
        ]);
        $input = $this->input();
        $role = $this->str($input, 'role');
        if (!isset(self::ROLES[$role])) {
            $this->json_error('Choose inventory personnel or delivery personnel.', 422);
        }
        $email = strtolower($this->str($input, 'email'));
        if ($this->UserModel->email_taken($email)) {
            $this->json_error('That email already belongs to an account.', 409);
        }
        $main = $this->db->table('training_centers')->select('id')->where('center_code', 'TESDA-MAIN')->get();
        if (!$main) {
            $this->json_error('The TESDA main office center (TESDA-MAIN) is missing.', 500);
        }
        $base = substr(strtolower(preg_replace('/[^a-z0-9.]+/i', '', strstr($email, '@', true))) ?: 'staff', 0, 90);
        $username = $base;
        for ($i = 2; $this->db->table('users')->where('username', $username)->get(); $i++) {
            $username = $base . $i;
        }
        $id = $this->UserModel->insert([
            'username' => $username,
            'full_name' => $this->str($input, 'full_name'),
            'email' => $email,
            'password' => password_hash((string) $input['password'], PASSWORD_DEFAULT),
            'role' => $role,
            'training_center_id' => (int) $main['id'],
            'contact_number' => $this->str($input, 'contact_number') ?: null,
            'is_active' => 1,
        ]);
        $this->AuditModel->log($this->user_id(), (int) $main['id'], 'created', 'user', $id,
            'Created ' . strtolower(self::ROLES[$role]) . " account for {$this->str($input, 'full_name')}");
        $this->json(['message' => self::ROLES[$role] . ' account created.'], 201);
    }

    /** PUT /staff/{id}/active  { is_active: 0|1 } */
    public function set_active($id)
    {
        $user = $this->UserModel->find((int) $id);
        if (!$user || !isset(self::ROLES[$user['role']])) {
            $this->json_error('Staff account not found.', 404);
        }
        $active = $this->str($this->input(), 'is_active') === '1' ? 1 : 0;
        $this->UserModel->update((int) $id, ['is_active' => $active]);
        $this->AuditModel->log($this->user_id(), $user['training_center_id'], $active ? 'activated' : 'deactivated', 'user', (int) $id,
            ($active ? 'Activated ' : 'Deactivated ') . $user['full_name']);
        $this->json(['message' => $active ? 'Account activated.' : 'Account deactivated. They can no longer log in.']);
    }
}
