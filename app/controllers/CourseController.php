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

    /** GET /courses */
    public function index()
    {
        $this->require_staff_admin();
        $this->json(['courses' => $this->CourseModel->listing($this->is_center_admin() ? $this->center_id() : null)]);
    }

    /** GET /course-options */
    public function form_options()
    {
        $this->require_staff_admin();
        $centers = $this->is_center_admin()
            ? array_values(array_filter([$this->TrainingCenterModel->find_active($this->center_id())]))
            : $this->TrainingCenterModel->all_active();
        $this->json(['training_centers' => $centers]);
    }

    /** GET /courses/{id} */
    public function detail($id)
    {
        $id = (int) $id;
        $role = $this->role();
        if (!in_array($role, ['admin', 'center_admin', 'instructor', 'student'], true)) {
            $this->json_error('This page is not available for your account role.', 403);
        }

        // Admins and students may open any course; center staff only their own center's.
        $scope = in_array($role, ['admin', 'student'], true) ? null : $this->center_id();
        $course = $this->CourseModel->detail($id, $scope);
        if (!$course) {
            $this->json_error('Course not found.', 404);
        }

        $enrollment = null;
        if ($role === 'student') {
            if (!$this->EnrollmentModel->exists(['student_id' => $this->user_id(), 'course_id' => $id, 'status' => 'enrolled'])) {
                $this->json_error('You must be enrolled to view this course.', 403);
            }
            foreach ($this->EnrollmentModel->for_student($this->user_id()) as $row) {
                if ((int) $row['course_id'] === $id) {
                    $enrollment = $row;
                    break;
                }
            }
        }

        $this->json([
            'course' => $course,
            'enrollment' => $enrollment,
            'roster' => $role === 'student' ? [] : $this->EnrollmentModel->roster_for_course($id),
            'materials' => $this->CourseMaterialModel->for_course($id),
        ]);
    }

    /** POST /instructor/courses/{id}/materials  { title, description, resource_url } */
    public function add_material($id)
    {
        $this->require_role('instructor', 'Instructor access is required.');
        $this->course_in_my_center((int) $id);
        $this->validate([
            'title|Title' => 'required|max_length[150]',
            'description|Description' => 'max_length[500]',
            'resource_url|Material link' => 'required|max_length[2048]',
        ]);
        $input = $this->input();
        $url = $this->str($input, 'resource_url');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            $this->json_error('Enter a valid http or https material link.', 422);
        }

        $this->CourseMaterialModel->insert([
            'course_id' => (int) $id,
            'uploaded_by' => $this->user_id(),
            'title' => $this->str($input, 'title'),
            'description' => $this->str($input, 'description'),
            'resource_url' => $url,
        ]);
        $this->json(['message' => 'Course material shared with enrolled students.'], 201);
    }

    /** PUT /instructor/courses/{id}  { class_days, class_schedule, course_outline } */
    public function update_delivery_details($id)
    {
        $this->require_role('instructor', 'Instructor access is required.');
        $this->course_in_my_center((int) $id);
        $this->validate([
            'class_days|Class days' => 'max_length[150]',
            'class_schedule|Schedule' => 'max_length[10000]',
            'course_outline|Course outline' => 'max_length[20000]',
        ]);
        $input = $this->input();
        $this->CourseModel->update((int) $id, [
            'class_days' => $this->str($input, 'class_days'),
            'class_schedule' => $this->str($input, 'class_schedule'),
            'course_outline' => $this->str($input, 'course_outline'),
        ]);
        $this->json(['message' => 'Course schedule and outline updated.']);
    }

    /** POST /courses */
    public function create()
    {
        $this->require_staff_admin();
        $this->save(null);
    }

    /** PUT /courses/{id} */
    public function update($id)
    {
        $this->require_staff_admin();
        $this->manageable_course((int) $id);
        $this->save((int) $id);
    }

    /** DELETE /courses/{id} */
    public function delete($id)
    {
        $this->require_staff_admin();
        $this->manageable_course((int) $id);
        $this->CourseModel->delete((int) $id);
        $this->json(['message' => 'Course deleted successfully.']);
    }

    private function save($id)
    {
        $this->validate([
            'course_code|Course code' => 'required|min_length[2]|max_length[40]|alpha_numeric_dash',
            'course_name|Course name' => 'required|max_length[150]',
            'qualification|Qualification' => 'required|min_length[2]|max_length[30]|alpha_numeric_space',
            'description|Description' => 'max_length[10000]',
            'capacity|Capacity' => 'required|numeric|greater_than_equal_to[1]|less_than_equal_to[10000]',
            'status|Status' => 'required|in_list[open,closed]',
            'class_days|Class days' => 'max_length[150]',
            'class_schedule|Schedule' => 'max_length[10000]',
            'course_outline|Course outline' => 'max_length[20000]',
        ]);

        $input = $this->input();
        $centerId = $this->is_center_admin() ? $this->center_id() : (int) ($input['training_center_id'] ?? 0);
        if (!$this->TrainingCenterModel->find_active($centerId)) {
            $this->json_error('Select an active training center.', 422);
        }
        $code = strtoupper($this->str($input, 'course_code'));
        if ($this->CourseModel->code_taken($code, $centerId, (int) $id)) {
            $this->json_error('That course code is already in use.', 422);
        }

        $course = [
            'training_center_id' => $centerId,
            'course_code' => $code,
            'course_name' => $this->str($input, 'course_name'),
            'qualification' => strtoupper($this->str($input, 'qualification')),
            'description' => $this->str($input, 'description'),
            'capacity' => (int) $input['capacity'],
            'status' => $this->str($input, 'status'),
            'class_days' => $this->str($input, 'class_days'),
            'class_schedule' => $this->str($input, 'class_schedule'),
            'course_outline' => $this->str($input, 'course_outline'),
        ];
        if ($id === null) {
            $this->CourseModel->insert($course);
        } else {
            $this->CourseModel->update($id, $course);
        }
        $this->json(['message' => 'Course saved successfully.'], $id === null ? 201 : 200);
    }

    private function require_staff_admin()
    {
        $this->require_role(['admin', 'center_admin'], 'Training center management access is required.');
    }

    /** The course, or a 404. Center admins can only manage their own center's courses. */
    private function manageable_course($id)
    {
        $course = $this->is_center_admin()
            ? $this->CourseModel->in_center($id, $this->center_id())
            : $this->CourseModel->find($id);
        if (!$course) {
            $this->json_error('Course not found.', 404);
        }
        return $course;
    }

    private function course_in_my_center($id)
    {
        $course = $this->CourseModel->in_center($id, $this->center_id());
        if (!$course) {
            $this->json_error('Course not found.', 404);
        }
        return $course;
    }
}
