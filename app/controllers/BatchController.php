<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Batches of a course: schedule, lifecycle (suspend, extend, complete, cancel),
 * trainee enrollment and transfers, training sessions and attendance, the
 * batch history, plus a center's delivery personnel.
 *
 * Access:
 *   admin         reads every center's batches (regional monitoring), changes nothing
 *   center_admin  manages its own center's batches
 *   instructor    reads its center's batches; records attendance for batches it handles
 */
class BatchController extends MY_Controller
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct()
    {
        parent::__construct();
        $this->require_role(['admin', 'center_admin', 'instructor'], 'This page is for TESDA staff.');
        $this->call->model('BatchModel');
        $this->call->model('CourseModel');
        $this->call->model('AuditModel');
    }

    /** GET /courses/{id}/batches : every batch of the course (the page splits active and history). */
    public function index($courseId)
    {
        $this->BatchModel->sweep();
        $course = $this->course_or_404((int) $courseId);
        $this->json(['course' => $course, 'batches' => $this->BatchModel->by_course((int) $courseId)]);
    }

    /** GET /batches : every batch at the user's center (learning center dashboard, allowance form). */
    public function center_batches()
    {
        $this->BatchModel->sweep();
        $this->json(['batches' => $this->center_id() ? $this->BatchModel->by_center($this->center_id()) : []]);
    }

    /** GET /batches/history?q=&course_id=&year=&center_id= */
    public function history()
    {
        $this->BatchModel->sweep();
        $get = $_GET;
        $filters = [
            'q' => mb_substr(trim((string) ($get['q'] ?? '')), 0, 100),
            'course_id' => (int) ($get['course_id'] ?? 0),
            'year' => (int) ($get['year'] ?? 0),
            'center_id' => $this->role() === 'admin' ? (int) ($get['center_id'] ?? 0) : 0,
        ];
        $this->json(['batches' => $this->BatchModel->history($this->center_scope(), $filters)]);
    }

    /** POST /courses/{id}/batches */
    public function create($courseId)
    {
        $this->require_center_admin('Batches are created by the learning center.');
        $course = $this->course_or_404((int) $courseId);
        if ($course['status'] === 'archived') {
            $this->json_error('This course is archived. Reopen it before adding batches.', 422);
        }
        $details = $this->batch_details();
        $instructorId = (int) ($this->input()['instructor_id'] ?? 0);
        $details['instructor_id'] = $instructorId ? $this->instructor_or_422($instructorId)['id'] : null;

        $id = $this->BatchModel->open((int) $courseId, $details, $this->user_id());
        $this->AuditModel->log($this->user_id(), $course['training_center_id'], 'created', 'batch', $id,
            "Opened {$details['batch_name']} for {$course['course_code']}");
        $this->json(['message' => 'Batch created.', 'batch' => $this->BatchModel->detail($id)], 201);
    }

    /** GET /batches/{id} : the batch page (details, trainees, sessions, activity). */
    public function show($batchId)
    {
        $this->BatchModel->sweep();
        $batch = $this->batch_or_404((int) $batchId);
        $this->call->model('BatchSessionModel');
        $this->json([
            'batch' => $batch,
            'students' => $this->BatchModel->roster((int) $batchId),
            'sessions' => $this->BatchSessionModel->for_batch((int) $batchId),
            'activity' => $this->AuditModel->for_entity('batch', (int) $batchId),
            'can_manage' => $this->is_center_admin(),
            'can_record_attendance' => $this->can_record_attendance($batch),
        ]);
    }

    /** PUT /batches/{id} : batch information and schedule. */
    public function update($batchId)
    {
        $batch = $this->managed_batch((int) $batchId);
        if ($batch['in_history']) {
            $this->json_error('This batch is in history. Its records can no longer be edited.', 422);
        }
        $details = $this->batch_details();
        $changes = [];
        if (($details['end_date'] ?? null) !== $batch['end_date']) {
            $changes[] = sprintf('end date %s → %s', $batch['end_date'] ?: 'not set', $details['end_date'] ?: 'not set');
        }
        if (($details['start_date'] ?? null) !== $batch['start_date']) {
            $changes[] = sprintf('start date %s → %s', $batch['start_date'] ?: 'not set', $details['start_date'] ?: 'not set');
        }
        if ($this->BatchModel->active_count($batch['id']) > $details['capacity']) {
            $this->json_error('Capacity cannot be lower than the number of active trainees.', 422);
        }
        // A batch that has not started yet follows its (new) start date.
        if ($batch['status'] === 'upcoming' && $details['start_date'] && $details['start_date'] <= date('Y-m-d')) {
            $details['status'] = 'ongoing';
        } elseif ($batch['status'] === 'ongoing' && $details['start_date'] && $details['start_date'] > date('Y-m-d') && !$batch['sessions_held']) {
            $details['status'] = 'upcoming';
        }
        if (!$batch['planned_end_date'] && $details['end_date']) {
            $details['planned_end_date'] = $details['end_date'];
        }
        $this->BatchModel->update($batch['id'], $details);
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'updated', 'batch', $batch['id'],
            "Updated {$details['batch_name']}" . ($changes ? ': ' . implode('; ', $changes) : ''));
        $this->json(['message' => 'Batch saved.', 'batch' => $this->BatchModel->detail($batch['id'])]);
    }

    /** PUT /batches/{id}/instructor  { instructor_id } (0 or empty to unassign) */
    public function assign_instructor($batchId)
    {
        $batch = $this->managed_batch((int) $batchId);
        if ($batch['in_history']) {
            $this->json_error('This batch is in history. Its teacher can no longer be changed.', 422);
        }
        $id = (int) ($this->input()['instructor_id'] ?? 0);
        $teacher = $id ? $this->instructor_or_422($id) : null;
        $this->BatchModel->assign_instructor($batch, $teacher['id'] ?? null);
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'assigned', 'batch', $batch['id'],
            $teacher ? "Assigned {$teacher['full_name']} to {$batch['batch_name']}" : "Removed the teacher from {$batch['batch_name']}");
        $this->json(['message' => $teacher ? "{$teacher['full_name']} now handles this batch." : 'Teacher removed.',
            'batch' => $this->BatchModel->detail($batch['id'])]);
    }

    /**
     * POST /batches/{id}/status  { action, reason, end_date }
     *   suspend  ongoing/upcoming -> suspended (reason required)
     *   resume   suspended -> ongoing/upcoming (end_date optional, to extend)
     *   extend   move the end date later (reason required)
     *   complete confirm completion (ongoing/suspended -> completed)
     *   cancel   the batch will not run or continue (reason required)
     */
    public function change_status($batchId)
    {
        $batch = $this->managed_batch((int) $batchId);
        $input = $this->input();
        $action = $this->str($input, 'action');
        $reason = mb_substr($this->str($input, 'reason'), 0, 500);
        $endDate = $this->str($input, 'end_date');
        if ($endDate !== '' && !$this->is_date($endDate)) {
            $this->json_error('Enter the new end date as YYYY-MM-DD.', 422);
        }
        $status = $batch['status'];
        $name = $batch['batch_name'];
        $center = $batch['training_center_id'];
        $today = date('Y-m-d');

        switch ($action) {
            case 'suspend':
                $this->expect($status, ['ongoing', 'upcoming'], 'Only an upcoming or ongoing batch can be suspended.');
                $this->need($reason, 'Give the reason for suspending training.');
                $this->BatchModel->update($batch['id'], ['status' => 'suspended', 'status_reason' => $reason, 'suspended_at' => date('Y-m-d H:i:s')]);
                $this->AuditModel->log($this->user_id(), $center, 'suspended', 'batch', $batch['id'], "Suspended {$name}: {$reason}");
                $message = 'Training suspended. The batch will not complete automatically until it is resumed.';
                break;

            case 'resume':
                $this->expect($status, ['suspended'], 'Only a suspended batch can be resumed.');
                $update = ['status' => $batch['start_date'] && $batch['start_date'] > $today ? 'upcoming' : 'ongoing',
                           'status_reason' => null, 'suspended_at' => null];
                $note = '';
                if ($endDate !== '') {
                    $this->later_end($batch, $endDate);
                    $update['end_date'] = $endDate;
                    $note = " and extended to {$endDate}";
                }
                $this->BatchModel->update($batch['id'], $update);
                $this->AuditModel->log($this->user_id(), $center, 'resumed', 'batch', $batch['id'],
                    "Resumed {$name}{$note}" . ($reason !== '' ? ": {$reason}" : ''));
                $message = 'Training resumed.';
                break;

            case 'extend':
                $this->expect($status, BatchModel::ACTIVE, 'Only an active batch can be extended.');
                $this->need($endDate, 'Choose the new end date.');
                $this->need($reason, 'Give the reason for the extension.');
                $this->later_end($batch, $endDate);
                $this->BatchModel->update($batch['id'], ['end_date' => $endDate]);
                $this->AuditModel->log($this->user_id(), $center, 'extended', 'batch', $batch['id'],
                    "Extended {$name} from " . ($batch['end_date'] ?: 'no end date') . " to {$endDate}: {$reason}");
                $message = 'Training extended to ' . date('M j, Y', strtotime($endDate)) . '.';
                break;

            case 'complete':
                $this->expect($status, ['ongoing', 'suspended'], 'Only an ongoing or suspended batch can be completed.');
                if (!$this->BatchModel->complete($batch, $this->user_id())) {
                    $this->json_error('The batch changed while you were working. Reload and try again.', 409);
                }
                $hours = $batch['required_hours'] ? " ({$batch['held_hours']} of {$batch['required_hours']} hours recorded)" : '';
                $this->AuditModel->log($this->user_id(), $center, 'completed', 'batch', $batch['id'],
                    "Confirmed completion of {$name}{$hours}" . ($reason !== '' ? ": {$reason}" : ''));
                $message = 'Training completed. The batch moved to history.';
                break;

            case 'cancel':
                $this->expect($status, BatchModel::ACTIVE, 'Only an active batch can be cancelled.');
                $this->need($reason, 'Give the reason for cancelling the batch.');
                $this->BatchModel->cancel($batch, $reason, $this->user_id());
                $this->AuditModel->log($this->user_id(), $center, 'cancelled', 'batch', $batch['id'], "Cancelled {$name}: {$reason}");
                $message = 'Batch cancelled. It moved to history with its records.';
                break;

            default:
                $this->json_error('Choose suspend, resume, extend, complete or cancel.', 422);
        }
        $this->json(['message' => $message, 'batch' => $this->BatchModel->detail($batch['id'])]);
    }

    /** POST /batches/{id}/students  { student_id } : enroll an existing student of the center. */
    public function enroll($batchId)
    {
        $batch = $this->managed_batch((int) $batchId);
        $this->call->model('StudentRecordModel');
        $this->call->model('StudentDocumentModel');
        if ($batch['in_history']) {
            $this->json_error('This batch is in history. Enroll the student in an active batch.', 422);
        }
        $studentId = (int) ($this->input()['student_id'] ?? 0);
        $student = $this->StudentRecordModel->find_in_center($studentId, $batch['training_center_id']);
        if (!$student || !(int) $student['is_active']) {
            $this->json_error('Select an active student of your learning center.', 422);
        }
        $membership = $this->BatchModel->membership($batch['id'], $studentId);
        if ($membership && $membership['status'] === 'active') {
            $this->json_error("{$student['full_name']} is already in this batch.", 409);
        }
        if ($membership && $membership['status'] === 'completed') {
            $this->json_error("{$student['full_name']} already completed this batch.", 409);
        }
        $other = $this->BatchModel->active_batch_in_course($studentId, $batch['course_id'], $batch['id']);
        if ($other) {
            $this->json_error("{$student['full_name']} is active in {$other['batch_name']} of this course. Transfer them from that batch instead.", 409);
        }
        if ($this->BatchModel->active_count($batch['id']) >= (int) $batch['capacity']) {
            $this->json_error('This batch is full. Raise its capacity or choose another batch.', 409);
        }

        $this->BatchModel->enroll($batch, $studentId, $this->user_id());
        $reused = $this->StudentDocumentModel->record_reuse($batch['training_center_id'], $studentId, $batch['batch_name'], $this->user_id());
        $docs = $this->StudentDocumentModel->checklist($batch['training_center_id'], $studentId, $student['civil_status']);
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'enrolled', 'batch', $batch['id'],
            "Enrolled {$student['full_name']} in {$batch['batch_name']}"
            . ($reused ? "; reused {$reused} verified document(s) on file" : ''));
        $this->json([
            'message' => "{$student['full_name']} enrolled. Classroom access is ready.",
            'documents' => $docs['summary'],
            'students' => $this->BatchModel->roster($batch['id']),
            'batch' => $this->BatchModel->detail($batch['id']),
        ], 201);
    }

    /** POST /batches/{id}/students/{studentId}/remove  { reason } */
    public function remove_student($batchId, $studentId)
    {
        $batch = $this->managed_batch((int) $batchId);
        $member = $this->active_member($batch, (int) $studentId);
        $reason = mb_substr($this->str($this->input(), 'reason'), 0, 255);
        $this->need($reason, 'Give the reason for removing the trainee.');
        $this->BatchModel->remove_member($batch, (int) $studentId, $reason, $this->user_id());
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'removed', 'batch', $batch['id'],
            "Removed {$member['full_name']} from {$batch['batch_name']}: {$reason}");
        $this->json(['message' => "{$member['full_name']} was removed. Their records stay on file.",
            'students' => $this->BatchModel->roster($batch['id']), 'batch' => $this->BatchModel->detail($batch['id'])]);
    }

    /** POST /batches/{id}/students/{studentId}/transfer  { batch_id, reason } */
    public function transfer_student($batchId, $studentId)
    {
        $batch = $this->managed_batch((int) $batchId);
        $member = $this->active_member($batch, (int) $studentId);
        $input = $this->input();
        $target = $this->BatchModel->detail((int) ($input['batch_id'] ?? 0), $batch['training_center_id']);
        $reason = mb_substr($this->str($input, 'reason'), 0, 255);
        if (!$target || (int) $target['course_id'] !== (int) $batch['course_id'] || (int) $target['id'] === (int) $batch['id']) {
            $this->json_error('Choose another batch of the same course.', 422);
        }
        if ($target['in_history']) {
            $this->json_error('That batch is in history. Choose an active batch.', 422);
        }
        if ($this->BatchModel->active_count($target['id']) >= (int) $target['capacity']) {
            $this->json_error("{$target['batch_name']} is full.", 409);
        }
        $existing = $this->BatchModel->membership($target['id'], (int) $studentId);
        if ($existing && $existing['status'] === 'completed') {
            $this->json_error("{$member['full_name']} already completed {$target['batch_name']}.", 409);
        }
        $this->BatchModel->transfer($batch, $target, (int) $studentId, $reason, $this->user_id());
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'transferred', 'batch', $batch['id'],
            "Transferred {$member['full_name']} from {$batch['batch_name']} to {$target['batch_name']}" . ($reason !== '' ? ": {$reason}" : ''));
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'enrolled', 'batch', $target['id'],
            "{$member['full_name']} transferred in from {$batch['batch_name']}");
        $this->json(['message' => "{$member['full_name']} moved to {$target['batch_name']}.",
            'students' => $this->BatchModel->roster($batch['id']), 'batch' => $this->BatchModel->detail($batch['id'])]);
    }

    /** GET /batches/{id}/students (teacher trainee list, allowance) */
    public function students($batchId)
    {
        $batch = $this->batch_or_404((int) $batchId);
        $this->json(['batch' => $batch, 'students' => $this->BatchModel->students((int) $batchId)]);
    }

    /**
     * POST /batches/{id}/sessions
     *   { session_date, start_time, end_time, hours, topic, status: held|cancelled, attendance: {studentId: mark} }
     * Records one training day; recording the same date again replaces it.
     */
    public function record_session($batchId)
    {
        $batch = $this->batch_or_404((int) $batchId);
        if (!$this->can_record_attendance($batch)) {
            $this->json_error('Only the batch teacher or the learning center can record attendance.', 403);
        }
        if (!in_array($batch['status'], ['ongoing'], true)) {
            $this->json_error('Attendance can only be recorded while the batch is ongoing.', 422);
        }
        $this->call->model('BatchSessionModel');
        $input = $this->input_json();
        $date = $this->str($input, 'session_date');
        $start = $this->str($input, 'start_time');
        $end = $this->str($input, 'end_time');
        $status = $this->str($input, 'status') ?: 'held';
        $topic = mb_substr($this->str($input, 'topic'), 0, 200);

        if (!$this->is_date($date)) {
            $this->json_error('Choose the session date.', 422);
        }
        if ($date > date('Y-m-d')) {
            $this->json_error('Sessions can only be recorded on or after the day they happen.', 422);
        }
        if ($batch['start_date'] && $date < $batch['start_date']) {
            $this->json_error('The session date is before the batch started.', 422);
        }
        if (!in_array($status, ['held', 'cancelled'], true)) {
            $this->json_error('Choose held or cancelled.', 422);
        }
        foreach (['start_time' => $start, 'end_time' => $end] as $label => $time) {
            if ($time !== '' && !$this->is_time($time)) {
                $this->json_error('Enter times as HH:MM.', 422);
            }
        }
        if ($start !== '' && $end !== '' && $end <= $start) {
            $this->json_error('The end time must be after the start time.', 422);
        }
        $hours = $this->str($input, 'hours');
        if ($hours === '' && $start !== '' && $end !== '') {
            $hours = (strtotime($end) - strtotime($start)) / 3600;
            // A full training day includes an unpaid hour of lunch break.
            if ($hours > 5) {
                $hours -= 1;
            }
        }
        $hours = $status === 'held' ? round((float) $hours, 2) : 0;
        if ($status === 'held' && ($hours <= 0 || $hours > 24)) {
            $this->json_error('Enter the training hours held (more than 0, up to 24).', 422);
        }

        // Marks for active trainees only; anyone not marked counts as absent.
        $active = array_column(array_filter($this->BatchModel->roster($batch['id']), fn ($s) => $s['status'] === 'active'), 'id');
        $given = is_array($input['attendance'] ?? null) ? $input['attendance'] : [];
        $marks = [];
        foreach ($active as $sid) {
            $mark = (string) ($given[$sid] ?? $given[(string) $sid] ?? 'absent');
            if (!in_array($mark, BatchSessionModel::MARKS, true)) {
                $this->json_error('Mark each trainee present, late, absent or excused.', 422);
            }
            $marks[(int) $sid] = $mark;
        }

        $this->BatchSessionModel->record($batch['id'], [
            'session_date' => $date,
            'start_time' => $start !== '' ? $start : null,
            'end_time' => $end !== '' ? $end : null,
            'hours' => $hours,
            'topic' => $topic !== '' ? $topic : null,
            'status' => $status,
        ], $marks, $this->user_id());
        $present = count(array_filter($marks, fn ($m) => $m === 'present' || $m === 'late'));
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'attendance', 'batch', $batch['id'],
            $status === 'held'
                ? "Recorded {$date}: {$hours} h, {$present} of " . count($marks) . ' present'
                : "Recorded {$date} as cancelled");
        $this->json([
            'message' => 'Session saved.',
            'sessions' => $this->BatchSessionModel->for_batch($batch['id']),
            'students' => $this->BatchModel->roster($batch['id']),
            'batch' => $this->BatchModel->detail($batch['id']),
        ]);
    }

    /** DELETE /batches/{id}/sessions/{sessionId} */
    public function delete_session($batchId, $sessionId)
    {
        $batch = $this->batch_or_404((int) $batchId);
        if (!$this->can_record_attendance($batch) || $batch['status'] !== 'ongoing') {
            $this->json_error('Sessions can only be changed by the batch teacher or the learning center while the batch is ongoing.', 403);
        }
        $this->call->model('BatchSessionModel');
        $session = $this->BatchSessionModel->in_batch((int) $sessionId, $batch['id']);
        if (!$session) {
            $this->json_error('Session not found.', 404);
        }
        $this->BatchSessionModel->delete((int) $sessionId);
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'attendance', 'batch', $batch['id'],
            "Deleted the session of {$session['session_date']}");
        $this->json(['message' => 'Session deleted.', 'sessions' => $this->BatchSessionModel->for_batch($batch['id']),
            'students' => $this->BatchModel->roster($batch['id']), 'batch' => $this->BatchModel->detail($batch['id'])]);
    }

    /** GET /center/instructors : teachers who can be assigned to batches. */
    public function instructors()
    {
        $this->require_center_admin('Only the learning center assigns teachers.');
        $rows = $this->db->table('users')
            ->select('id, full_name, email')
            ->where(['role' => 'instructor', 'is_active' => 1, 'training_center_id' => $this->center_id()])
            ->order_by('full_name', 'ASC')
            ->get_all();
        $this->json(['instructors' => $rows]);
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

    /* ---------------------------------------------------------------- */

    /** Validated batch information and schedule fields from the request. */
    private function batch_details()
    {
        $this->validate([
            'batch_name|Batch name' => 'required|max_length[100]',
            'capacity|Capacity' => 'numeric|greater_than_equal_to[1]|less_than_equal_to[500]',
            'total_hours|Total training hours' => 'numeric|greater_than_equal_to[1]|less_than_equal_to[5000]',
            'training_days|Number of training days' => 'numeric|greater_than_equal_to[1]|less_than_equal_to[1000]',
            'venue|Venue or room' => 'max_length[150]',
            'session_frequency|Session frequency' => 'max_length[60]',
        ]);
        $input = $this->input();
        $start = $this->str($input, 'start_date');
        $end = $this->str($input, 'end_date');
        foreach ([$start, $end] as $date) {
            if ($date !== '' && !$this->is_date($date)) {
                $this->json_error('Enter dates as YYYY-MM-DD.', 422);
            }
        }
        if ($start !== '' && $end !== '' && $end < $start) {
            $this->json_error('The expected completion date must be on or after the start date.', 422);
        }
        $sessionStart = $this->str($input, 'session_start');
        $sessionEnd = $this->str($input, 'session_end');
        foreach ([$sessionStart, $sessionEnd] as $time) {
            if ($time !== '' && !$this->is_time($time)) {
                $this->json_error('Enter session times as HH:MM.', 422);
            }
        }
        if ($sessionStart !== '' && $sessionEnd !== '' && $sessionEnd <= $sessionStart) {
            $this->json_error('The daily end time must be after the start time.', 422);
        }
        $days = array_values(array_intersect(self::DAYS, array_map('strtolower', array_map('trim',
            explode(',', $this->str($input, 'days_of_week'))))));
        $mode = $this->str($input, 'training_mode');
        if ($mode !== '' && !in_array($mode, ['face_to_face', 'online', 'hybrid'], true)) {
            $this->json_error('Choose face-to-face, online or hybrid.', 422);
        }
        $int = fn ($key) => $this->str($input, $key) !== '' ? (int) $input[$key] : null;
        return [
            'batch_name' => $this->str($input, 'batch_name'),
            'start_date' => $start ?: null,
            'end_date' => $end ?: null,
            'capacity' => $int('capacity') ?? 25,
            'total_hours' => $int('total_hours'),
            'training_days' => $int('training_days'),
            'days_of_week' => $days ? implode(',', $days) : null,
            'session_start' => $sessionStart ?: null,
            'session_end' => $sessionEnd ?: null,
            'venue' => $this->str($input, 'venue') ?: null,
            'session_frequency' => $this->str($input, 'session_frequency') ?: null,
            'training_mode' => $mode ?: null,
            'requires_confirmation' => in_array($this->str($input, 'requires_confirmation'), ['1', 'true', 'on'], true) ? 1 : 0,
        ];
    }

    /** The raw JSON body (keeps nested objects such as attendance marks). */
    private function input_json()
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    private function course_or_404($courseId)
    {
        $course = $this->CourseModel->detail($courseId, $this->center_scope());
        if (!$course) {
            $this->json_error('Course not found.', 404);
        }
        return $course;
    }

    /** The batch if the user may see it: admins any, center staff their center's. */
    private function batch_or_404($batchId)
    {
        $batch = $this->BatchModel->detail($batchId, $this->center_scope());
        if (!$batch) {
            $this->json_error('Batch not found.', 404);
        }
        return $batch;
    }

    /** The batch for a change: center admins of the batch's center only. */
    private function managed_batch($batchId)
    {
        $this->require_center_admin('Only the learning center administrator can change batches.');
        return $this->batch_or_404($batchId);
    }

    private function active_member(array $batch, $studentId)
    {
        foreach ($this->BatchModel->roster($batch['id']) as $row) {
            if ((int) $row['id'] === $studentId && $row['status'] === 'active') {
                return $row;
            }
        }
        $this->json_error('That trainee is not active in this batch.', 404);
    }

    private function instructor_or_422($id)
    {
        $teacher = $this->db->table('users')
            ->select('id, full_name')
            ->where(['id' => (int) $id, 'role' => 'instructor', 'is_active' => 1, 'training_center_id' => $this->center_id()])
            ->get();
        if (!$teacher) {
            $this->json_error('Choose an active teacher of your learning center.', 422);
        }
        return $teacher;
    }

    private function can_record_attendance(array $batch)
    {
        return $this->is_center_admin()
            || ($this->role() === 'instructor' && (int) $batch['instructor_id'] === $this->user_id());
    }

    private function require_center_admin($message)
    {
        if (!$this->is_center_admin()) {
            $this->json_error($message, 403);
        }
    }

    private function later_end(array $batch, $endDate)
    {
        if ($batch['start_date'] && $endDate < $batch['start_date']) {
            $this->json_error('The new end date must be on or after the start date.', 422);
        }
        if ($batch['end_date'] && $endDate <= $batch['end_date']) {
            $this->json_error('An extension must move the end date later than ' . date('M j, Y', strtotime($batch['end_date'])) . '.', 422);
        }
    }

    private function expect($status, array $allowed, $message)
    {
        if (!in_array($status, $allowed, true)) {
            $this->json_error($message, 422);
        }
    }

    private function need($value, $message)
    {
        if (trim((string) $value) === '') {
            $this->json_error($message, 422);
        }
    }

    private function is_date($value)
    {
        $d = DateTime::createFromFormat('Y-m-d', (string) $value);
        return $d && $d->format('Y-m-d') === $value;
    }

    private function is_time($value)
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $value);
    }
}
