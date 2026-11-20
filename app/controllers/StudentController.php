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

    /** GET /student/courses */
    public function courses()
    {
        $this->json(['enrollments' => $this->EnrollmentModel->for_student($this->user_id())]);
    }

    /** GET /student/profile */
    public function profile()
    {
        $this->json($this->profile_data());
    }

    /** PUT /student/profile  { full_name, email } */
    public function update_profile()
    {
        $this->validate([
            'full_name|Name' => 'required|max_length[150]',
            'email|Email' => 'required|valid_email|max_length[255]',
        ]);
        $input = $this->input();
        $name = $this->str($input, 'full_name');
        $email = $this->str($input, 'email');
        if ($this->UserModel->email_taken($email, $this->user_id())) {
            $this->json_error('That email address is already in use.', 422);
        }

        $this->UserModel->update($this->user_id(), ['full_name' => $name, 'email' => $email]);
        $this->session->set_userdata('full_name', $name);
        $this->json(array_merge(['message' => 'Your profile was updated.'], $this->profile_data()));
    }

    private function profile_data()
    {
        $user = $this->UserModel->find($this->user_id());
        if (!$user || $user['role'] !== 'student') {
            $this->json_error('This profile is not available.', 403);
        }
        return [
            'name' => $user['full_name'] ?: $user['username'],
            'email' => $user['email'],
            'training_center_name' => (string) $this->session->userdata('training_center_name'),
            'enrollments' => $this->EnrollmentModel->for_student($this->user_id()),
        ];
    }
}
