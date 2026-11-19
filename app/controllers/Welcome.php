<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

class Welcome extends SpaController
{
	public function index()
	{
		$this->json(['app' => 'TESDA NEXUS API', 'status' => 'ok']);
	}

	public function dashboard()
	{
		$this->require_login();
		$role = $this->role();

		if ($role === 'admin') {
			$this->call->model('CourseModel');
			$this->call->model('EnrollmentModel');
			$this->call->model('UserModel');
			$this->call->model('ProductModel');
			$this->json([
				'role' => $role,
				'analytics' => [
					'students' => (int) $this->UserModel->count_by_role('student'),
					'instructors' => (int) $this->UserModel->count_by_role('instructor'),
					'courses' => (int) $this->CourseModel->count(),
					'enrollments' => (int) $this->EnrollmentModel->count(),
					'products' => count($this->ProductModel->read())
				]
			]);
		}

		if ($role === 'center_admin') {
			$centerId = $this->center_id();
			$this->call->model('CourseModel');
			$this->call->model('UserModel');
			$this->call->model('CenterApplicationModel');
			$this->json([
				'role' => $role,
				'center_name' => (string) $this->session->userdata('training_center_name'),
				'analytics' => [
					'students' => (int) $this->UserModel->count_by_role('student', $centerId),
					'instructors' => (int) $this->UserModel->count_by_role('instructor', $centerId),
					'courses' => (int) $this->CourseModel->count($centerId),
					'applications' => (int) $this->CenterApplicationModel->pending_count($centerId)
				]
			]);
		}

		if ($role === 'instructor') {
			$this->json(['role' => $role, 'redirect' => '/instructor']);
		}

		$this->call->model('EnrollmentModel');
		$this->json([
			'role' => $role,
			'enrollments' => $this->EnrollmentModel->for_student($this->user_id())
		]);
	}
}
