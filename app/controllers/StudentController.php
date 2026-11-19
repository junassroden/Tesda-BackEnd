<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

class StudentController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role('student');
        $this->call->model('EnrollmentModel');
        $this->call->model('UserModel');
    }

    public function courses()
    {
        $this->json(['enrollments' => $this->EnrollmentModel->for_student($this->user_id())]);
    }

    public function profile()
    {
        $this->json($this->profile_data());
    }

    public function update_profile()
    {
        $userId = $this->user_id();
        $input = $this->input();
        $name = $this->str($input, 'full_name');
        $email = $this->str($input, 'email');
        $errors = [];

        if ($name === '' || strlen($name) > 150) {
            $errors[] = 'Name is required and must be at most 150 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $errors[] = 'Enter a valid email address.';
        } else {
            $duplicate = $this->db->raw(
                'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1',
                [$email, $userId]
            )->fetch(PDO::FETCH_ASSOC);
            if ($duplicate) {
                $errors[] = 'That email address is already in use.';
            }
        }
        if ($errors) {
            $this->json_errors($errors);
        }

        $this->UserModel->update_student_profile($userId, $name, $email);
        $this->session->set_userdata('full_name', $name);
        $this->json(array_merge(['message' => 'Your profile was updated.'], $this->profile_data()));
    }

    private function profile_data()
    {
        $userId = $this->user_id();
        $user = $this->UserModel->find($userId);
        if (!$user || $user['role'] !== 'student') {
            $this->json_error('This profile is not available.', 403);
        }
        return [
            'name' => $user['full_name'] ?: $user['username'],
            'email' => $user['email'],
            'training_center_name' => (string) $this->session->userdata('training_center_name'),
            'enrollments' => $this->EnrollmentModel->for_student($userId)
        ];
    }
}
