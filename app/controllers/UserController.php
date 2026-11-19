<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

class UserController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(['admin', 'center_admin'], 'Training center management access is required.');
        $this->call->model('UserModel');
        $this->call->model('TrainingCenterModel');
        $this->call->model('CenterApplicationModel');
    }

    public function index()
    {
        $this->json([
            'users' => $this->UserModel->all($this->is_center_admin() ? $this->center_id() : null),
            'current_user_id' => $this->user_id()
        ]);
    }

    public function form_options()
    {
        $this->json(['training_centers' => $this->form_centers()]);
    }

    public function show($id)
    {
        $user = $this->manageable_user($id);
        unset($user['password']);
        $this->json(['account' => $user]);
    }

    public function create()
    {
        $account = $this->apply_center_scope($this->account_input());
        $errors = $this->validate_account($account, null, true);
        if ($errors) {
            $this->json_errors($errors);
        }
        $this->UserModel->create_account(
            $account['username'],
            $account['email'],
            $account['password'],
            $account['role'],
            $account['full_name'],
            $account['is_active'],
            $account['training_center_id']
        );
        $this->json(['message' => 'Account created successfully.'], 201);
    }

    public function update($id)
    {
        $this->manageable_user($id);
        $account = $this->apply_center_scope($this->account_input());
        $errors = $this->validate_account($account, (int) $id, false);
        if ($errors) {
            $this->json_errors($errors);
        }
        $this->UserModel->update_account(
            (int) $id,
            $account['username'],
            $account['email'],
            $account['role'],
            $account['full_name'],
            $account['is_active'],
            $account['training_center_id'],
            $account['password']
        );
        $this->json(['message' => 'Account updated successfully.']);
    }

    public function delete($id)
    {
        $this->manageable_user($id);
        if ((int) $id === $this->user_id()) {
            $this->json_error('You cannot delete the account you are using.', 409);
        }
        $this->UserModel->delete_account((int) $id);
        $this->json(['message' => 'Account deleted successfully.']);
    }

    private function manageable_user($id)
    {
        $user = $this->UserModel->find((int) $id);
        if (!$user || !$this->can_manage_user($user)) {
            $this->json_error('Account not found.', 404);
        }
        return $user;
    }

    private function account_input()
    {
        $input = $this->input();
        $active = $input['is_active'] ?? '1';
        return [
            'username' => $this->str($input, 'username'),
            'email' => $this->str($input, 'email'),
            'full_name' => $this->str($input, 'full_name'),
            'role' => $this->str($input, 'role'),
            'password' => (string) ($input['password'] ?? ''),
            'is_active' => ($active === '1' || $active === 1 || $active === true) ? 1 : 0,
            'training_center_id' => (int) ($input['training_center_id'] ?? 0) ?: null
        ];
    }

    private function validate_account($account, $excludeId, $passwordRequired)
    {
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $account['username'])) {
            $errors[] = 'Username must be 3 to 100 characters and use letters, numbers, dots, underscores, or hyphens.';
        }
        if (!filter_var($account['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($account['full_name'] === '' || strlen($account['full_name']) > 150) {
            $errors[] = 'Full name is required and must be at most 150 characters.';
        }
        $allowedRoles = $this->is_center_admin()
            ? ['instructor', 'student']
            : ['admin', 'center_admin', 'instructor', 'student'];
        if (!in_array($account['role'], $allowedRoles, true)) {
            $errors[] = 'Select a valid account role.';
        }
        if ($account['role'] !== 'admin' && $account['training_center_id'] === null) {
            $errors[] = 'Select a training center for center organizer, instructor, and student accounts.';
        } elseif ($account['training_center_id'] !== null) {
            $center = $this->db->raw(
                'SELECT id FROM training_centers WHERE id = ? AND is_active = 1 LIMIT 1',
                [$account['training_center_id']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$center) {
                $errors[] = 'Select an active training center.';
            }
        }
        if ($passwordRequired && $account['password'] === '') {
            $errors[] = 'A password is required for new accounts.';
        }
        if ($account['password'] !== '' && strlen($account['password']) < 4) {
            $errors[] = 'Password must contain at least 4 characters.';
        }
        $sql = 'SELECT COUNT(*) AS total FROM users WHERE (email = ? OR username = ?)';
        $args = [$account['email'], $account['username']];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $excludeId;
        }
        $existing = $this->db->raw($sql, $args)->fetch(PDO::FETCH_ASSOC);
        if ((int) ($existing['total'] ?? 0) > 0) {
            $errors[] = 'That email address or username is already in use.';
        }
        return $errors;
    }

    private function form_centers()
    {
        if (!$this->is_center_admin()) {
            return $this->TrainingCenterModel->all_active();
        }
        $center = $this->TrainingCenterModel->find_active($this->center_id());
        return $center ? [$center] : [];
    }

    private function apply_center_scope($account)
    {
        if ($this->is_center_admin()) {
            $account['training_center_id'] = $this->center_id();
        }
        return $account;
    }

    private function can_manage_user($user)
    {
        if (in_array(
            $this->CenterApplicationModel->status_for_user((int) $user['id']),
            ['pending', 'rejected'],
            true
        )) {
            return false;
        }
        if (!$this->is_center_admin()) {
            return true;
        }
        return (int) ($user['training_center_id'] ?? 0) === $this->center_id()
            && in_array($user['role'] ?? '', ['student', 'instructor'], true);
    }
}
