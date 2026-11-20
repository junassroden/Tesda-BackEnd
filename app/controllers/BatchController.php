<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

/** Batches of a program, the trainees in a batch, and a center's delivery personnel. */
class BatchController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(['admin', 'center_admin', 'instructor'], 'This page is for TESDA staff.');
        $this->call->model('BatchModel');
        $this->call->model('CourseModel');
        $this->call->model('AuditModel');
    }

    /** GET /courses/{id}/batches */
    public function index($courseId)
    {
        $course = $this->course_or_404((int) $courseId);
        $this->json(['course' => $course, 'batches' => $this->BatchModel->by_course((int) $courseId)]);
    }

    /** GET /batches : every batch at the user's center (learning center dashboard, allowance form). */
    public function center_batches()
    {
        $this->json(['batches' => $this->center_id() ? $this->BatchModel->by_center($this->center_id()) : []]);
    }

    /** POST /courses/{id}/batches  { batch_name, start_date, end_date, capacity } */
    public function create($courseId)
    {
        $this->require_role(['center_admin', 'instructor'], 'Batches are created by the learning center.');
        $course = $this->course_or_404((int) $courseId);
        $this->validate([
            'batch_name|Batch name' => 'required|max_length[100]',
            'start_date|Start date' => 'pattern[date_ymd]',
            'end_date|End date' => 'pattern[date_ymd]',
            'capacity|Capacity' => 'numeric|greater_than_equal_to[1]|less_than_equal_to[500]',
        ]);
        $input = $this->input();
        $name = $this->str($input, 'batch_name');
        $start = $this->str($input, 'start_date');
        $end = $this->str($input, 'end_date');
        if ($start !== '' && $end !== '' && $end < $start) {
            $this->json_error('The end date must be after the start date.', 422);
        }
        $capacity = $this->str($input, 'capacity') !== '' ? (int) $input['capacity'] : 25;

        $instructorId = $this->role() === 'instructor' ? $this->user_id() : null;
        $id = $this->BatchModel->open((int) $courseId, $name, $start, $end, $capacity, $instructorId, $this->user_id());
        $this->AuditModel->log($this->user_id(), $course['training_center_id'], 'created', 'batch', $id, "Opened {$name} for {$course['course_code']}");
        $this->json(['message' => 'Batch created.', 'batch' => $this->BatchModel->detail($id)], 201);
    }

    /** GET /batches/{id}/students */
    public function students($batchId)
    {
        $batch = $this->BatchModel->detail((int) $batchId, $this->center_scope());
        if (!$batch) {
            $this->json_error('Batch not found.', 404);
        }
        $this->json(['batch' => $batch, 'students' => $this->BatchModel->students((int) $batchId)]);
    }

    /** GET /delivery-personnel */
    public function delivery()
    {
        $this->call->model('DeliveryPersonnelModel');
        $this->json(['personnel' => $this->DeliveryPersonnelModel->listing($this->center_scope())]);
    }

    /** POST /delivery-personnel  { full_name, contact_number, vehicle, plate_number } */
    public function add_delivery()
    {
        $this->require_role('center_admin', 'Only learning center administrators can add delivery personnel.');
        $this->validate([
            'full_name|Full name' => 'required|max_length[150]',
            'contact_number|Contact number' => 'min_length[7]|max_length[30]|pattern[tel]',
            'vehicle|Vehicle' => 'max_length[100]',
            'plate_number|Plate number' => 'max_length[20]',
        ]);
        $input = $this->input();
        $name = $this->str($input, 'full_name');

        $this->call->model('DeliveryPersonnelModel');
        $id = $this->DeliveryPersonnelModel->insert([
            'training_center_id' => $this->center_id(),
            'full_name' => $name,
            'contact_number' => $this->str($input, 'contact_number') ?: null,
            'vehicle' => $this->str($input, 'vehicle') ?: null,
            'plate_number' => strtoupper($this->str($input, 'plate_number')) ?: null,
            'is_active' => 1,
        ]);
        $this->AuditModel->log($this->user_id(), $this->center_id(), 'created', 'delivery_personnel', $id, "Added delivery personnel {$name}");
        $this->json(['message' => "{$name} added.", 'personnel' => $this->DeliveryPersonnelModel->listing($this->center_id())], 201);
    }

    /** The program, or a 404. Center staff only see their own center's programs. */
    private function course_or_404($courseId)
    {
        $course = $this->CourseModel->detail($courseId, $this->center_scope());
        if (!$course) {
            $this->json_error('Program not found.', 404);
        }
        return $course;
    }
}
