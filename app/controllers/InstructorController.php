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

    /** GET /instructor */
    public function index()
    {
        $centerId = $this->center_id();
        $this->json([
            'students' => $this->UserModel->students($centerId),
            'courses' => $this->CourseModel->open($centerId),
            'enrollments' => $this->EnrollmentModel->for_instructor($this->user_id(), $centerId),
        ]);
    }

    /** POST /instructor/enroll  { student_id, course_id } */
    public function enroll()
    {
        $this->validate([
            'student_id|Student' => 'required|numeric',
            'course_id|Course' => 'required|numeric',
        ]);
        $input = $this->input();
        $studentId = (int) $input['student_id'];
        $courseId = (int) $input['course_id'];
        $centerId = $this->center_id();

        $student = $this->UserModel->find($studentId);
        if (!$student || $student['role'] !== 'student' || !(int) $student['is_active']
            || (int) $student['training_center_id'] !== $centerId) {
            $this->json_error('Select an active student account.', 422);
        }
        $course = $this->CourseModel->in_center($courseId, $centerId);
        if (!$course || $course['status'] !== 'open') {
            $this->json_error('Select an open course.', 422);
        }
        if ($this->EnrollmentModel->exists(['student_id' => $studentId, 'course_id' => $courseId])) {
            $this->json_error('This student is already enrolled in that course.', 409);
        }
        if ($this->EnrollmentModel->enrolled_count($courseId) >= (int) $course['capacity']) {
            $this->json_error('This course has reached its enrollment capacity.', 409);
        }

        $this->EnrollmentModel->insert([
            'student_id' => $studentId,
            'course_id' => $courseId,
            'instructor_id' => $this->user_id(),
            'status' => 'enrolled',
        ]);
        $this->json(['message' => 'Student enrolled successfully.'], 201);
    }
}
