<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthController extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('UserModel');
    }

    /** GET /session */
    public function session_info()
    {
        $this->json(['user' => $this->session_user()]);
    }

    /** POST /login  { email (or username), password } */
    public function login()
    {
        $input = $this->input();
        $identity = $this->str($input, 'email') ?: $this->str($input, 'username');
        $password = (string) ($input['password'] ?? '');
        if ($identity === '' || $password === '') {
            $this->json_error('Enter your email and password.', 422);
        }

        $user = $this->UserModel->find_by_identity($identity);
        if (!$user || !password_verify($password, $user['password'])) {
            $this->json_error('The email or password is incorrect.', 401);
        }

        if (!(int) $user['is_active']) {
            $this->call->model('CenterApplicationModel');
            $status = $this->CenterApplicationModel->status_for_user((int) $user['id']);
            $messages = [
                'pending' => 'Your application is awaiting review by your selected training center.',
                'rejected' => 'Your application was not approved. Contact your selected training center for details.',
            ];
            $this->json_error($messages[$status] ?? 'This account is inactive. Contact your training center administrator.', 403);
        }

        $center = null;
        if (!empty($user['training_center_id'])) {
            $this->call->model('TrainingCenterModel');
            $center = $this->TrainingCenterModel->find((int) $user['training_center_id']);
        }

        $this->session->regenerate_on_login();
        $this->session->set_userdata([
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'] ?: $user['username'],
            'user_role' => $user['role'],
            'training_center_id' => $user['training_center_id'] ?? null,
            'training_center_name' => $center['name'] ?? '',
        ]);
        $this->UserModel->record_login((int) $user['id']);
        $this->json(['user' => $this->session_user()]);
    }

    /** POST /logout */
    public function logout()
    {
        $this->session->sess_destroy();
        $this->json(['message' => 'Logged out.']);
    }
}
