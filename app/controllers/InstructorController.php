<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

class InstructorController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role('instructor', 'Instructor access is required.');
        if ($this->center_id() < 1) {
            $this->json_error('Ask an administrator to assign your account to a training center.', 403);
        }
        $this->call->model('UserModel');
        $this->call->model('CourseModel');
        $this->call->model('EnrollmentModel');
    }

    public function index()
    {
        $centerId = $this->center_id();
        $this->json([
            'students' => $this->UserModel->students($centerId),
            'courses' => $this->CourseModel->open($centerId),
            'enrollments' => $this->EnrollmentModel->for_instructor($this->user_id(), $centerId)
        ]);
    }

    public function enroll()
    {
        $input = $this->input();
        $studentId = (int) ($input['student_id'] ?? 0);
        $courseId = (int) ($input['course_id'] ?? 0);
        $student = $this->UserModel->find($studentId);
        $course = $this->CourseModel->find($courseId);
        $centerId = $this->center_id();

        if (!$student || $student['role'] !== 'student' || (int) $student['is_active'] !== 1
            || (int) $student['training_center_id'] !== $centerId) {
            $this->json_error('Select an active student account.', 422);
        }
        if (!$course || $course['status'] !== 'open' || (int) $course['training_center_id'] !== $centerId) {
            $this->json_error('Select an open course.', 422);
        }
        if ($this->EnrollmentModel->exists($studentId, $courseId)) {
            $this->json_error('This student is already enrolled in that course.', 409);
        }
        if ($this->EnrollmentModel->course_enrollment_count($courseId) >= (int) $course['capacity']) {
            $this->json_error('This course has reached its enrollment capacity.', 409);
        }

        $this->EnrollmentModel->add($studentId, $courseId, $this->user_id());
        $this->json(['message' => 'Student enrolled successfully.'], 201);
    }
}
