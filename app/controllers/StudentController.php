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

    /**
     * GET /student/training : every batch the student is or was in, with the
     * course, schedule, training status, progress and their own attendance.
     */
    public function training()
    {
        $this->call->model('BatchModel');
        $this->call->model('BatchSessionModel');
        $this->BatchModel->sweep();
        $memberships = $this->db->table('batch_students')
            ->select('batch_id, status, assigned_at, ended_at, end_reason')
            ->where('student_id', $this->user_id())
            ->order_by('assigned_at', 'DESC')
            ->get_all();

        $training = [];
        foreach ($memberships as $m) {
            $batch = $this->BatchModel->detail((int) $m['batch_id']);
            if (!$batch) {
                continue;
            }
            $course = $this->db->table('courses')
                ->select('description, duration_hours, training_days, duration_value, duration_unit, training_mode')
                ->where('id', (int) $batch['course_id'])->get();
            $attendance = $this->BatchSessionModel->for_student($batch['id'], $this->user_id());
            $attended = count(array_filter($attendance, fn ($a) => in_array($a['status'], ['present', 'late'], true)));
            $hoursAttended = array_sum(array_map(fn ($a) => in_array($a['status'], ['present', 'late'], true) ? (float) $a['hours'] : 0, $attendance));
            $training[] = [
                'membership_status' => $m['status'],
                'assigned_at' => $m['assigned_at'],
                'ended_at' => $m['ended_at'],
                'end_reason' => $m['end_reason'],
                'batch' => $batch,
                'course' => $course,
                'attendance' => [
                    'sessions_held' => count($attendance),
                    'attended' => $attended,
                    'hours_attended' => round($hoursAttended, 2),
                    'rate' => count($attendance) ? (int) round($attended / count($attendance) * 100) : null,
                    'recent' => array_slice($attendance, 0, 10),
                ],
            ];
        }
        $this->json(['training' => $training]);
    }

    /** GET /student/profile */
    public function profile()
    {
        $this->json($this->profile_data());
    }

    /**
     * PUT /student/profile
     *   { full_name, email, contact_number, address, civil_status, highest_education, guardian_name, guardian_contact }
     * Civil status decides whether a marriage contract is required.
     */
    public function update_profile()
    {
        $this->validate([
            'full_name|Name' => 'required|max_length[150]',
            'email|Email' => 'required|valid_email|max_length[255]',
            'contact_number|Contact number' => 'max_length[30]',
            'address|Address' => 'max_length[255]',
            'highest_education|Highest education' => 'max_length[100]',
            'guardian_name|Guardian or emergency contact' => 'max_length[150]',
            'guardian_contact|Guardian contact number' => 'max_length[30]',
        ]);
        $input = $this->input();
        $name = $this->str($input, 'full_name');
        $email = $this->str($input, 'email');
        if ($this->UserModel->email_taken($email, $this->user_id())) {
            $this->json_error('That email address is already in use.', 422);
        }
        $civil = $this->str($input, 'civil_status');
        if ($civil !== '' && !in_array($civil, ['single', 'married', 'widowed', 'separated'], true)) {
            $this->json_error('Choose a civil status.', 422);
        }

        $this->UserModel->update($this->user_id(), [
            'full_name' => $name,
            'email' => $email,
            'contact_number' => $this->str($input, 'contact_number') ?: null,
        ]);
        $profileFields = [];
        foreach (['address', 'highest_education', 'guardian_name', 'guardian_contact'] as $key) {
            if (array_key_exists($key, $input)) {
                $profileFields[$key] = $this->str($input, $key) ?: null;
            }
        }
        if (array_key_exists('civil_status', $input)) {
            $profileFields['civil_status'] = $civil ?: null;
        }
        if ($profileFields && $this->db->table('student_profiles')->where('user_id', $this->user_id())->get()) {
            $this->db->table('student_profiles')->where('user_id', $this->user_id())->update($profileFields);
        }
        $this->session->set_userdata('full_name', $name);
        $this->json(array_merge(['message' => 'Your profile was updated.'], $this->profile_data()));
    }

    private function profile_data()
    {
        $user = $this->UserModel->find($this->user_id());
        if (!$user || $user['role'] !== 'student') {
            $this->json_error('This profile is not available.', 403);
        }
        $profile = $this->db->table('student_profiles')
            ->select('student_number, first_name, middle_name, last_name, birthdate, sex, civil_status, highest_education,
                      address, guardian_name, guardian_contact')
            ->where('user_id', $this->user_id())
            ->get() ?: null;
        return [
            'name' => $user['full_name'] ?: $user['username'],
            'email' => $user['email'],
            'contact_number' => $user['contact_number'],
            'training_center_name' => (string) $this->session->userdata('training_center_name'),
            'profile' => $profile,
            'enrollments' => $this->EnrollmentModel->for_student($this->user_id()),
        ];
    }
}
