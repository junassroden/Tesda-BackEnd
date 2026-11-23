<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

/**
 * The learning center's student records: search, create (without duplicating
 * an existing person), profile, document checklist and enrollment history.
 * Center administrators only, and only for their own center's students.
 */
class StudentRecordController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role('center_admin', 'Student records are managed by the learning center.');
        if ($this->center_id() < 1) {
            $this->json_error('Your account is not assigned to a learning center.', 403);
        }
        $this->call->model('StudentRecordModel');
        $this->call->model('StudentDocumentModel');
        $this->call->model('AuditModel');
    }

    /** GET /center/students?q= */
    public function index()
    {
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $students = $this->StudentRecordModel->search($this->center_id(), $q);
        $completeness = $this->StudentDocumentModel->completeness($this->center_id(), $students);
        foreach ($students as &$s) {
            $s['documents'] = $completeness[$s['id']] ?? ['required' => 0, 'verified' => 0, 'pending' => 0, 'rejected' => 0];
            unset($s['bank_account_number'], $s['bank_name'], $s['bank_account_holder']);
        }
        $this->json([
            'students' => $students,
            'pending_documents' => $this->StudentDocumentModel->pending_count($this->center_id()),
        ]);
    }

    /**
     * POST /center/students
     *   { first_name, middle_name, last_name, birthdate, sex, civil_status, highest_education, address,
     *     guardian_name, guardian_contact, email, contact_number, password, confirm_new }
     * Refuses a second record for someone already at the center (same name and
     * birthdate) unless confirm_new = 1, so one person keeps one profile.
     */
    public function create()
    {
        $this->validate(array_merge($this->profile_rules(), [
            'email|Email' => 'required|valid_email|max_length[255]',
            'password|Initial password' => 'required|min_length[8]|max_length[72]',
        ]));
        $input = $this->input();
        $profile = $this->profile_from($input);
        $email = strtolower($this->str($input, 'email'));

        $this->call->model('UserModel');
        if ($this->UserModel->email_taken($email)) {
            $this->json_error('That email already belongs to an account. Search for the student instead of creating a new record.', 409);
        }
        $matches = $this->StudentRecordModel->possible_duplicates($this->center_id(), $profile['first_name'], $profile['last_name'], $profile['birthdate']);
        if ($matches && $this->str($input, 'confirm_new') !== '1') {
            $this->json_error('A student with the same name' . ($profile['birthdate'] ? ' and birthdate' : '') . ' is already on record. Use the existing record, or confirm this is a different person.', 409, [
                'duplicates' => array_map(fn ($m) => [
                    'id' => (int) $m['id'], 'full_name' => $m['full_name'], 'student_number' => $m['student_number'],
                    'email' => $m['email'], 'birthdate' => $m['birthdate'],
                ], $matches),
            ]);
        }

        $this->call->model('TrainingCenterModel');
        $center = $this->TrainingCenterModel->find($this->center_id());
        $id = $this->StudentRecordModel->create_student($center, $profile, $email, $this->str($input, 'contact_number'),
            (string) $input['password'], $this->user_id());
        $student = $this->StudentRecordModel->find_in_center($id, $this->center_id());
        $this->AuditModel->log($this->user_id(), $this->center_id(), 'created', 'student', $id,
            "Created student record {$student['student_number']} {$student['full_name']}");
        $this->json(['message' => "{$student['full_name']} added as {$student['student_number']}.", 'student' => $student], 201);
    }

    /** GET /center/students/{id} : profile, documents, review history, enrollment history. */
    public function show($id)
    {
        $student = $this->student_or_404((int) $id);
        $list = $this->StudentDocumentModel->checklist($this->center_id(), $student['id'], $student['civil_status']);
        $this->json([
            'student' => $student,
            'documents' => $list['items'],
            'summary' => $list['summary'],
            'history' => $this->StudentDocumentModel->student_history($this->center_id(), $student['id']),
            'enrollments' => $this->StudentRecordModel->enrollment_history($student['id'], $this->center_id()),
        ]);
    }

    /** PUT /center/students/{id} : profile and contact details. */
    public function update($id)
    {
        $student = $this->student_or_404((int) $id);
        $this->validate(array_merge($this->profile_rules(), [
            'email|Email' => 'required|valid_email|max_length[255]',
        ]));
        $input = $this->input();
        $email = strtolower($this->str($input, 'email'));
        $this->call->model('UserModel');
        if ($this->UserModel->email_taken($email, $student['id'])) {
            $this->json_error('That email is used by another account.', 422);
        }
        $this->call->model('TrainingCenterModel');
        $center = $this->TrainingCenterModel->find($this->center_id());
        $this->StudentRecordModel->save_profile($student['id'], $center, $this->profile_from($input), [
            'email' => $email,
            'contact_number' => $this->str($input, 'contact_number') ?: null,
        ]);
        $this->AuditModel->log($this->user_id(), $this->center_id(), 'updated', 'student', $student['id'], "Updated the profile of {$student['full_name']}");
        $this->json(['message' => 'Profile saved.', 'student' => $this->student_or_404($student['id'])]);
    }

    /* ---------------------------------------------------------------- */

    private function profile_rules()
    {
        return [
            'first_name|First name' => 'required|max_length[80]',
            'middle_name|Middle name' => 'max_length[80]',
            'last_name|Last name' => 'required|max_length[80]',
            'highest_education|Highest education' => 'max_length[100]',
            'address|Address' => 'max_length[255]',
            'guardian_name|Guardian or emergency contact' => 'max_length[150]',
            'guardian_contact|Guardian contact number' => 'max_length[30]',
            'contact_number|Contact number' => 'max_length[30]',
        ];
    }

    private function profile_from(array $input)
    {
        $birthdate = $this->str($input, 'birthdate');
        if ($birthdate !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $birthdate);
            if (!$d || $d->format('Y-m-d') !== $birthdate || $birthdate >= date('Y-m-d')) {
                $this->json_error('Enter a valid birthdate.', 422);
            }
        }
        $sex = $this->str($input, 'sex');
        if ($sex !== '' && !in_array($sex, ['male', 'female'], true)) {
            $this->json_error('Choose male or female.', 422);
        }
        $civil = $this->str($input, 'civil_status');
        if ($civil !== '' && !in_array($civil, ['single', 'married', 'widowed', 'separated'], true)) {
            $this->json_error('Choose a civil status.', 422);
        }
        $value = fn ($key) => $this->str($input, $key) !== '' ? $this->str($input, $key) : null;
        return [
            'first_name' => $this->str($input, 'first_name'),
            'middle_name' => $value('middle_name'),
            'last_name' => $this->str($input, 'last_name'),
            'birthdate' => $birthdate ?: null,
            'sex' => $sex ?: null,
            'civil_status' => $civil ?: null,
            'highest_education' => $value('highest_education'),
            'address' => $value('address'),
            'guardian_name' => $value('guardian_name'),
            'guardian_contact' => $value('guardian_contact'),
        ];
    }

    private function student_or_404($id)
    {
        $student = $this->StudentRecordModel->find_in_center($id, $this->center_id());
        if (!$student) {
            $this->json_error('Student not found.', 404);
        }
        return $student;
    }
}
