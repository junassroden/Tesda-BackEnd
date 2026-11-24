<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * batches (+ batch_students, batch_sessions): classes of a course, their
 * schedule and lifecycle, and the trainees in them.
 *
 * A batch's virtual classroom has no table of its own: its members are the
 * batch's teacher and its active/completed trainees, so enrolling, removing
 * or transferring a trainee here is what grants or ends classroom access.
 */
class BatchModel extends Model
{
    protected $table = 'batches';
    protected $primary_key = 'id';
    protected $fillable = [
        'course_id', 'instructor_id', 'batch_number', 'batch_name', 'start_date', 'end_date',
        'total_hours', 'training_days', 'days_of_week', 'session_start', 'session_end', 'venue',
        'session_frequency', 'training_mode', 'planned_end_date', 'requires_confirmation',
        'capacity', 'status', 'status_reason', 'suspended_at', 'completed_at', 'completed_by',
        'completion_mode', 'created_by',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->call->library('BatchLifecycle'); // LavaLust loader (app/libraries/BatchLifecycle.php)
        $this->call->library('Ledger');
    }

    public const ACTIVE = ['upcoming', 'ongoing', 'suspended'];
    public const HISTORY = ['completed', 'cancelled'];

    /** Applies the automatic start/complete rules (see libraries/BatchLifecycle.php). */
    public function sweep()
    {
        $this->db->transaction();
        try {
            $result = BatchLifecycle::run(fn ($sql, $params) => $this->db->raw($sql, $params));
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            $this->db->roll_back();
            error_log('Batch lifecycle sweep failed: ' . $e->getMessage());
            return ['started' => [], 'completed' => []];
        }
    }

    /** Batch columns plus course, center and teacher names. */
    private function with_names()
    {
        return $this->db->table('batches b')
            ->select('b.id, b.course_id, b.instructor_id, b.batch_number, b.batch_name, b.start_date, b.end_date,
                      b.planned_end_date, b.total_hours, b.training_days, b.days_of_week, b.session_start, b.session_end,
                      b.venue, b.session_frequency, b.training_mode AS batch_training_mode, b.requires_confirmation,
                      b.capacity, b.status, b.status_reason, b.suspended_at, b.completed_at, b.completion_mode,
                      b.created_at, c.course_name, c.course_code, c.qualification, c.training_center_id,
                      c.duration_hours AS course_hours, c.training_mode AS course_training_mode,
                      tc.name AS training_center_name, u.full_name AS instructor_name, u.email AS instructor_email,
                      cb.full_name AS completed_by_name')
            ->inner_join('courses c', 'c.id = b.course_id')
            ->inner_join('training_centers tc', 'tc.id = c.training_center_id')
            ->left_join('users u', 'u.id = b.instructor_id')
            ->left_join('users cb', 'cb.id = b.completed_by');
    }

    public function by_course($courseId)
    {
        $batches = $this->with_names()
            ->where('b.course_id', (int) $courseId)
            ->order_by('b.batch_number', 'ASC')
            ->get_all();
        return $this->decorate($batches);
    }

    /** Every batch at a center: ongoing first, then upcoming, then the rest. */
    public function by_center($centerId)
    {
        return $this->ranked($this->decorate($this->with_names()
            ->where('c.training_center_id', (int) $centerId)
            ->order_by('b.start_date', 'ASC')
            ->get_all()));
    }

    /** Batches a teacher handles at their center: ongoing first, then upcoming, then the rest. */
    public function by_instructor($instructorId, $centerId)
    {
        return $this->ranked($this->decorate($this->with_names()
            ->where('b.instructor_id', (int) $instructorId)
            ->where('c.training_center_id', (int) $centerId)
            ->order_by('b.start_date', 'ASC')
            ->get_all()));
    }

    /**
     * Completed and cancelled batches, newest first. $centerId null = every center (main office).
     * filters: q (batch, course, teacher), course_id, year (completion year).
     */
    public function history($centerId, array $filters = [])
    {
        $query = $this->with_names()->in('b.status', self::HISTORY);
        if ($centerId) {
            $query->where('c.training_center_id', (int) $centerId);
        } elseif (!empty($filters['center_id'])) {
            $query->where('c.training_center_id', (int) $filters['center_id']);
        }
        if (!empty($filters['course_id'])) {
            $query->where('b.course_id', (int) $filters['course_id']);
        }
        if (!empty($filters['year'])) {
            $year = (int) $filters['year'];
            $query->where('COALESCE(b.completed_at, b.end_date)', '>=', "{$year}-01-01")
                  ->where('COALESCE(b.completed_at, b.end_date)', '<', ($year + 1) . '-01-01');
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->grouped(function ($q) use ($like) {
                $q->like('b.batch_name', $like)->or_like('c.course_name', $like)
                  ->or_like('c.course_code', $like)->or_like('u.full_name', $like);
            });
        }
        $rows = $query->order_by('COALESCE(b.completed_at, b.end_date)', 'DESC')->get_all();
        return $this->decorate($rows);
    }

    /** One batch, optionally only if it belongs to the center. */
    public function detail($batchId, $centerId = null)
    {
        $query = $this->with_names()->where('b.id', (int) $batchId);
        if ($centerId) {
            $query->where('c.training_center_id', (int) $centerId);
        }
        $batch = $query->get();
        return $batch ? $this->decorate([$batch])[0] : null;
    }

    /** Active and completed trainees (the allowance and teacher lists use this). */
    public function students($batchId)
    {
        return $this->db->table('batch_students bs')
            ->select('u.id, u.full_name, u.email, sp.student_number, sp.bank_name,
                      sp.bank_account_number, sp.bank_account_holder, bs.status')
            ->inner_join('users u', 'u.id = bs.student_id')
            ->left_join('student_profiles sp', 'sp.user_id = bs.student_id')
            ->where('bs.batch_id', (int) $batchId)
            ->in('bs.status', ['active', 'completed'])
            ->order_by('u.full_name', 'ASC')
            ->get_all();
    }

    /**
     * Everyone who was ever in the batch (including dropped and transferred),
     * with attendance figures and document completeness.
     */
    public function roster($batchId)
    {
        $rows = $this->db->table('batch_students bs')
            ->select('bs.id AS membership_id, u.id, u.full_name, u.email, u.contact_number, sp.student_number,
                      sp.bank_account_number, bs.status, bs.assigned_at, bs.ended_at, bs.end_reason,
                      bs.transferred_to_batch_id, tb.batch_name AS transferred_to_batch_name')
            ->inner_join('users u', 'u.id = bs.student_id')
            ->left_join('student_profiles sp', 'sp.user_id = bs.student_id')
            ->left_join('batches tb', 'tb.id = bs.transferred_to_batch_id')
            ->where('bs.batch_id', (int) $batchId)
            ->order_by('u.full_name', 'ASC')
            ->get_all();
        if (!$rows) {
            return [];
        }

        $held = $this->db->raw(
            "SELECT COUNT(*) AS sessions FROM batch_sessions WHERE batch_id = ? AND status = 'held'",
            [(int) $batchId]
        )->fetch(PDO::FETCH_ASSOC);
        $sessions = (int) ($held['sessions'] ?? 0);
        $marks = $this->db->raw(
            "SELECT a.student_id,
                    SUM(a.status IN ('present', 'late')) AS attended,
                    SUM(a.status = 'late') AS late,
                    SUM(a.status = 'absent') AS absent,
                    SUM(a.status = 'excused') AS excused
               FROM batch_attendance a
              INNER JOIN batch_sessions s ON s.id = a.session_id AND s.status = 'held'
              WHERE s.batch_id = ?
              GROUP BY a.student_id",
            [(int) $batchId]
        )->fetchAll(PDO::FETCH_ASSOC);
        $byStudent = array_column($marks, null, 'student_id');

        foreach ($rows as &$row) {
            $m = $byStudent[$row['id']] ?? [];
            $row['attended'] = (int) ($m['attended'] ?? 0);
            $row['late'] = (int) ($m['late'] ?? 0);
            $row['absent'] = (int) ($m['absent'] ?? 0);
            $row['excused'] = (int) ($m['excused'] ?? 0);
            $row['sessions_held'] = $sessions;
            $row['attendance_rate'] = $sessions ? round($row['attended'] / $sessions * 100) : null;
        }
        return $rows;
    }

    /** Opens the next numbered batch for a course. Returns the new id. */
    public function open($courseId, array $details, $createdBy)
    {
        $last = $this->db->table($this->table)
            ->select_max('batch_number', 'last_number')
            ->where('course_id', (int) $courseId)
            ->get();

        $start = $details['start_date'] ?? null;
        return $this->insert(array_merge($details, [
            'course_id' => (int) $courseId,
            'batch_number' => (int) ($last['last_number'] ?? 0) + 1,
            'planned_end_date' => $details['end_date'] ?? null,
            'status' => $start && $start <= date('Y-m-d') ? 'ongoing' : 'upcoming',
            'created_by' => $createdBy,
        ]));
    }

    /** Is the student active in some batch of this course (other than $exceptBatch)? Returns that batch or null. */
    public function active_batch_in_course($studentId, $courseId, $exceptBatch = 0)
    {
        return $this->db->table('batch_students bs')
            ->select('b.id, b.batch_name')
            ->inner_join('batches b', 'b.id = bs.batch_id')
            ->where(['bs.student_id' => (int) $studentId, 'b.course_id' => (int) $courseId, 'bs.status' => 'active'])
            ->where('b.id', '!=', (int) $exceptBatch)
            ->get() ?: null;
    }

    public function active_count($batchId)
    {
        return (int) $this->db->table('batch_students')
            ->where(['batch_id' => (int) $batchId, 'status' => 'active'])
            ->count();
    }

    public function membership($batchId, $studentId)
    {
        return $this->db->table('batch_students')
            ->where(['batch_id' => (int) $batchId, 'student_id' => (int) $studentId])
            ->get() ?: null;
    }

    /**
     * Adds a student to a batch and to the batch's course enrollment, in one
     * transaction. A student who left the batch earlier is re-activated on
     * their old row so the history row is reused (one row per batch/student).
     */
    public function enroll(array $batch, $studentId, $userId)
    {
        $this->db->transaction();
        try {
            $this->add_member($batch, $studentId, $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** Ends a student's membership (dropped). Their records stay; classroom access ends. */
    public function remove_member(array $batch, $studentId, $reason, $userId)
    {
        $this->db->transaction();
        try {
            $this->end_member($batch, $studentId, 'dropped', $reason, null, $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** Moves a student to another batch of the same course. */
    public function transfer(array $from, array $to, $studentId, $reason, $userId)
    {
        $this->db->transaction();
        try {
            $this->end_member($from, $studentId, 'transferred', $reason, (int) $to['id'], $userId);
            $this->add_member($to, $studentId, $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** Assigns the teacher and moves the batch's active course enrollments to them. */
    public function assign_instructor(array $batch, $instructorId)
    {
        $this->db->transaction();
        try {
            $this->update((int) $batch['id'], ['instructor_id' => $instructorId ?: null]);
            $this->db->raw(
                "UPDATE enrollments e
                  INNER JOIN batch_students bs ON bs.student_id = e.student_id AND bs.batch_id = ? AND bs.status = 'active'
                    SET e.instructor_id = ?
                  WHERE e.course_id = ? AND e.status = 'enrolled'",
                [(int) $batch['id'], $instructorId ?: null, (int) $batch['course_id']]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** Manual completion by an administrator (overrides the hour rule). */
    public function complete(array $batch, $userId)
    {
        $this->db->transaction();
        try {
            $done = BatchLifecycle::complete(fn ($sql, $params) => $this->db->raw($sql, $params), $batch, $userId, 'manual');
            $this->db->commit();
            return $done;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** Cancels a batch that will not run. Active trainees are dropped with the reason; records stay. */
    public function cancel(array $batch, $reason, $userId)
    {
        $this->db->transaction();
        try {
            $this->update((int) $batch['id'], ['status' => 'cancelled', 'status_reason' => $reason, 'suspended_at' => null]);
            $active = $this->db->table('batch_students')->select('student_id')
                ->where(['batch_id' => (int) $batch['id'], 'status' => 'active'])->get_all();
            foreach ($active as $row) {
                $this->end_member($batch, (int) $row['student_id'], 'dropped', 'Batch cancelled: ' . $reason, null, $userId);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    private function add_member(array $batch, $studentId, $userId)
    {
        $existing = $this->membership($batch['id'], $studentId);
        if ($existing) {
            $this->db->table('batch_students')->where('id', (int) $existing['id'])->update([
                'status' => 'active', 'assigned_by' => $userId, 'assigned_at' => date('Y-m-d H:i:s'),
                'ended_at' => null, 'ended_by' => null, 'end_reason' => null, 'transferred_to_batch_id' => null,
            ]);
        } else {
            $this->db->table('batch_students')->insert([
                'batch_id' => (int) $batch['id'], 'student_id' => (int) $studentId,
                'status' => 'active', 'assigned_by' => $userId,
            ]);
        }

        // One enrollment row per student and course (unique key); re-use it for a retake.
        $enrollment = $this->db->table('enrollments')
            ->where(['student_id' => (int) $studentId, 'course_id' => (int) $batch['course_id']])->get();
        if ($enrollment) {
            $this->db->table('enrollments')->where('id', (int) $enrollment['id'])->update([
                'status' => 'enrolled', 'instructor_id' => $batch['instructor_id'] ?: null,
            ]);
        } else {
            $this->db->table('enrollments')->insert([
                'student_id' => (int) $studentId, 'course_id' => (int) $batch['course_id'],
                'instructor_id' => $batch['instructor_id'] ?: null, 'status' => 'enrolled',
            ]);
        }
    }

    private function end_member(array $batch, $studentId, $status, $reason, $toBatchId, $userId)
    {
        $this->db->table('batch_students')
            ->where(['batch_id' => (int) $batch['id'], 'student_id' => (int) $studentId, 'status' => 'active'])
            ->update([
                'status' => $status, 'ended_at' => date('Y-m-d H:i:s'), 'ended_by' => $userId,
                'end_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'transferred_to_batch_id' => $toBatchId,
            ]);
        // Drop the course enrollment unless the student is still active in another batch of it.
        if (!$toBatchId && !$this->active_batch_in_course($studentId, $batch['course_id'], $batch['id'])) {
            $this->db->table('enrollments')
                ->where(['student_id' => (int) $studentId, 'course_id' => (int) $batch['course_id'], 'status' => 'enrolled'])
                ->update(['status' => 'dropped']);
        }
    }

    private function ranked(array $batches)
    {
        $rank = ['ongoing' => 0, 'suspended' => 1, 'upcoming' => 2, 'completed' => 3, 'cancelled' => 4];
        usort($batches, fn ($a, $b) => ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9));
        return $batches;
    }

    /**
     * Adds to each batch: enrolled (active + completed trainees), held_hours,
     * sessions_held, required_hours, progress (0-100 or null), training_mode,
     * needs_review (past the end date but not completed) and in_history.
     */
    private function decorate(array $batches)
    {
        if (!$batches) {
            return $batches;
        }
        $ids = array_column($batches, 'id');
        $rows = $this->db->table('batch_students')
            ->select('batch_id')
            ->select_count('id', 'total')
            ->in('batch_id', $ids)
            ->in('status', ['active', 'completed'])
            ->group_by('batch_id')
            ->get_all();
        $counts = array_column($rows, 'total', 'batch_id');

        $hours = $this->db->table('batch_sessions')
            ->select('batch_id')
            ->select_sum('hours', 'held_hours')
            ->select_count('id', 'sessions_held')
            ->in('batch_id', $ids)
            ->where('status', 'held')
            ->group_by('batch_id')
            ->get_all();
        $hoursBy = array_column($hours, null, 'batch_id');

        $today = date('Y-m-d');
        foreach ($batches as &$batch) {
            $batch['enrolled'] = (int) ($counts[$batch['id']] ?? 0);
            $batch['held_hours'] = round((float) ($hoursBy[$batch['id']]['held_hours'] ?? 0), 2);
            $batch['sessions_held'] = (int) ($hoursBy[$batch['id']]['sessions_held'] ?? 0);
            $required = $batch['total_hours'] ?? $batch['course_hours'] ?? null;
            $batch['required_hours'] = $required !== null ? (int) $required : null;
            $batch['progress'] = $required ? min(100, (int) floor($batch['held_hours'] / $required * 100)) : null;
            $batch['training_mode'] = $batch['batch_training_mode'] ?: $batch['course_training_mode'];
            $batch['requires_confirmation'] = (int) $batch['requires_confirmation'];
            $batch['in_history'] = in_array($batch['status'], self::HISTORY, true);
            $batch['needs_review'] = $batch['status'] === 'ongoing' && $batch['end_date'] && $batch['end_date'] < $today;
            $batch['review_reason'] = null;
            if ($batch['needs_review']) {
                if ($batch['requires_confirmation']) {
                    $batch['review_reason'] = 'End date passed. This batch needs an administrator to confirm completion.';
                } elseif ($required === null) {
                    $batch['review_reason'] = 'End date passed. Set the required training hours or confirm completion.';
                } else {
                    $batch['review_reason'] = sprintf('End date passed with %s of %d required hours recorded. Extend the batch or confirm completion.',
                        rtrim(rtrim(number_format($batch['held_hours'], 2), '0'), '.'), $required);
                }
            }
        }
        return $batches;
    }
}
