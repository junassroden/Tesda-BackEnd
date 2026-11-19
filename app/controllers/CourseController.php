<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

class CourseController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_login();
        $this->call->model('CourseModel');
        $this->call->model('TrainingCenterModel');
        $this->call->model('EnrollmentModel');
        $this->call->model('CourseMaterialModel');
    }

    public function index()
    {
        $this->require_staff_admin();
        $this->json([
            'courses' => $this->CourseModel->all($this->is_center_admin() ? $this->center_id() : null)
        ]);
    }

    public function form_options()
    {
        $this->require_staff_admin();
        $this->json(['training_centers' => $this->form_centers()]);
    }

    public function detail($id)
    {
        $userId = $this->user_id();
        $role = $this->role();
        $course = in_array($role, ['admin', 'student'], true)
            ? $this->CourseModel->find((int) $id)
            : $this->CourseModel->for_center((int) $id, $this->center_id());

        if (!$course) {
            $this->json_error('Course not found.', 404);
        }

        $enrollment = null;
        if ($role === 'student') {
            if (!$this->EnrollmentModel->student_is_enrolled($userId, (int) $id)) {
                $this->json_error('You must be enrolled to view this course.', 403);
            }
            foreach ($this->EnrollmentModel->for_student($userId) as $studentCourse) {
                if ((int) $studentCourse['course_id'] === (int) $id) {
                    $enrollment = $studentCourse;
                    break;
                }
            }
        } elseif (!in_array($role, ['admin', 'center_admin', 'instructor'], true)) {
            $this->json_error('This page is not available for your account role.', 403);
        }

        $this->json([
            'course' => $course,
            'enrollment' => $enrollment,
            'roster' => $role === 'student' ? [] : $this->EnrollmentModel->roster_for_course((int) $id),
            'materials' => $this->CourseMaterialModel->for_course((int) $id)
        ]);
    }

    public function add_material($id)
    {
        $this->require_role('instructor', 'Instructor access is required.');
        if (!$this->CourseModel->for_center((int) $id, $this->center_id())) {
            $this->json_error('Course not found.', 404);
        }

        $input = $this->input();
        $title = $this->str($input, 'title');
        $description = $this->str($input, 'description');
        $url = $this->str($input, 'resource_url');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (
            $title === ''
            || strlen($title) > 150
            || strlen($description) > 500
            || strlen($url) > 2048
            || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array($scheme, ['http', 'https'], true)
        ) {
            $this->json_error('Enter a title, an optional description, and a valid http or https material link.', 422);
        }

        $this->CourseMaterialModel->add((int) $id, $this->user_id(), $title, $description, $url);
        $this->json(['message' => 'Course material shared with enrolled students.'], 201);
    }

    public function update_delivery_details($id)
    {
        $this->require_role('instructor', 'Instructor access is required.');
        if (!$this->CourseModel->for_center((int) $id, $this->center_id())) {
            $this->json_error('Course not found.', 404);
        }

        $input = $this->input();
        $classDays = $this->str($input, 'class_days');
        $classSchedule = $this->str($input, 'class_schedule');
        $courseOutline = $this->str($input, 'course_outline');
        if (strlen($classDays) > 150 || strlen($classSchedule) > 10000 || strlen($courseOutline) > 20000) {
            $this->json_error('Days, schedule, or outline exceed the allowed length.', 422);
        }

        $this->CourseModel->update_delivery_details((int) $id, $classDays, $classSchedule, $courseOutline);
        $this->json(['message' => 'Course schedule and outline updated.']);
    }

    public function create()
    {
        $this->require_staff_admin();
        $this->save(null);
    }

    public function update($id)
    {
        $this->require_staff_admin();
        if (!$this->find_manageable_course((int) $id)) {
            $this->json_error('Course not found.', 404);
        }
        $this->save((int) $id);
    }

    public function delete($id)
    {
        $this->require_staff_admin();
        if (!$this->find_manageable_course((int) $id)) {
            $this->json_error('Course not found.', 404);
        }
        $this->CourseModel->delete((int) $id);
        $this->json(['message' => 'Course deleted successfully.']);
    }

    private function save($id)
    {
        $input = $this->input();
        $course = [
            'course_code' => strtoupper($this->str($input, 'course_code')),
            'course_name' => $this->str($input, 'course_name'),
            'qualification' => strtoupper($this->str($input, 'qualification')),
            'description' => $this->str($input, 'description'),
            'capacity' => (int) ($input['capacity'] ?? 0),
            'status' => $this->str($input, 'status'),
            'training_center_id' => (int) ($input['training_center_id'] ?? 0),
            'class_days' => $this->str($input, 'class_days'),
            'class_schedule' => $this->str($input, 'class_schedule'),
            'course_outline' => $this->str($input, 'course_outline')
        ];
        if ($this->is_center_admin()) {
            $course['training_center_id'] = $this->center_id();
        }

        $errors = $this->validate_course($course, $id);
        if ($errors) {
            $this->json_errors($errors);
        }

        $this->CourseModel->save(
            $course['course_code'],
            $course['course_name'],
            $course['qualification'],
            $course['description'],
            $course['capacity'],
            $course['status'],
            $course['training_center_id'],
            $course['class_days'],
            $course['class_schedule'],
            $course['course_outline'],
            $id
        );
        $this->json(['message' => 'Course saved successfully.'], $id === null ? 201 : 200);
    }

    private function validate_course($course, $excludeId)
    {
        $errors = [];
        if (!preg_match('/^[A-Z0-9-]{2,40}$/', $course['course_code'])) {
            $errors[] = 'Course code must use 2 to 40 letters, numbers, or hyphens.';
        }
        if ($course['course_name'] === '' || strlen($course['course_name']) > 150) {
            $errors[] = 'Course name is required and must be at most 150 characters.';
        }
        if (!preg_match('/^[A-Z0-9 -]{2,30}$/', $course['qualification'])) {
            $errors[] = 'Enter a valid qualification such as NC II.';
        }
        if ($course['capacity'] < 1 || $course['capacity'] > 10000) {
            $errors[] = 'Capacity must be between 1 and 10,000.';
        }
        if (strlen($course['description']) > 10000 || strlen($course['class_days']) > 150) {
            $errors[] = 'Description or class days exceed the allowed length.';
        }
        if (strlen($course['class_schedule']) > 10000 || strlen($course['course_outline']) > 20000) {
            $errors[] = 'Schedule or course outline exceeds the allowed length.';
        }
        if (!in_array($course['status'], ['open', 'closed'], true)) {
            $errors[] = 'Select a valid course status.';
        }
        $center = $this->db->raw(
            'SELECT id FROM training_centers WHERE id = ? AND is_active = 1 LIMIT 1',
            [$course['training_center_id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$center) {
            $errors[] = 'Select an active training center.';
        }
        if ($this->is_center_admin() && (int) $course['training_center_id'] !== $this->center_id()) {
            $errors[] = 'You can only manage courses for your assigned training center.';
        }
        $sql = 'SELECT COUNT(*) AS total FROM courses WHERE course_code = ? AND training_center_id = ?';
        $args = [$course['course_code'], $course['training_center_id']];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $excludeId;
        }
        $existing = $this->db->raw($sql, $args)->fetch(PDO::FETCH_ASSOC);
        if ((int) ($existing['total'] ?? 0) > 0) {
            $errors[] = 'That course code is already in use.';
        }
        return $errors;
    }

    private function require_staff_admin()
    {
        $this->require_role(['admin', 'center_admin'], 'Training center management access is required.');
    }

    private function form_centers()
    {
        if (!$this->is_center_admin()) {
            return $this->TrainingCenterModel->all_active();
        }
        return array_values(array_filter([$this->TrainingCenterModel->find_active($this->center_id())]));
    }

    private function find_manageable_course($id)
    {
        if ($this->is_center_admin()) {
            return $this->CourseModel->for_center($id, $this->center_id());
        }
        return $this->CourseModel->find($id);
    }
}
