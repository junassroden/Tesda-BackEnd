<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** batches (+ batch_students): classes of a course and the trainees in them. */
class BatchModel extends Model
{
    protected $table = 'batches';
    protected $primary_key = 'id';
    protected $fillable = [
        'course_id', 'instructor_id', 'batch_number', 'batch_name', 'start_date', 'end_date',
        'capacity', 'status', 'created_by',
    ];

    /** Batch columns plus course and teacher names. */
    private function with_names()
    {
        return $this->db->table('batches b')
            ->select('b.id, b.course_id, b.batch_number, b.batch_name, b.start_date, b.end_date, b.capacity, b.status,
                      c.course_name, c.course_code, c.training_center_id, u.full_name AS instructor_name')
            ->inner_join('courses c', 'c.id = b.course_id')
            ->left_join('users u', 'u.id = b.instructor_id');
    }

    public function by_course($courseId)
    {
        $batches = $this->with_names()
            ->where('b.course_id', (int) $courseId)
            ->order_by('b.batch_number', 'ASC')
            ->get_all();
        return $this->attach_enrolled($batches);
    }

    /** Every batch at a center: ongoing first, then upcoming, then the rest. */
    public function by_center($centerId)
    {
        $batches = $this->attach_enrolled($this->with_names()
            ->where('c.training_center_id', (int) $centerId)
            ->order_by('b.start_date', 'ASC')
            ->get_all());

        $rank = ['ongoing' => 0, 'upcoming' => 1, 'completed' => 2, 'cancelled' => 3];
        usort($batches, fn ($a, $b) => ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9));
        return $batches;
    }

    /** Batches a teacher handles at their center: ongoing first, then upcoming, then the rest. */
    public function by_instructor($instructorId, $centerId)
    {
        $batches = $this->attach_enrolled($this->with_names()
            ->where('b.instructor_id', (int) $instructorId)
            ->where('c.training_center_id', (int) $centerId)
            ->order_by('b.start_date', 'ASC')
            ->get_all());

        $rank = ['ongoing' => 0, 'upcoming' => 1, 'completed' => 2, 'cancelled' => 3];
        usort($batches, fn ($a, $b) => ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9));
        return $batches;
    }

    /** One batch, optionally only if it belongs to the center. */
    public function detail($batchId, $centerId = null)
    {
        $query = $this->with_names()->where('b.id', (int) $batchId);
        if ($centerId) {
            $query->where('c.training_center_id', (int) $centerId);
        }
        $batch = $query->get();
        return $batch ? $this->attach_enrolled([$batch])[0] : null;
    }

    public function students($batchId)
    {
        return $this->db->table('batch_students bs')
            ->select('u.id, u.full_name, u.email, sp.student_number, sp.bank_name,
                      sp.bank_account_number, sp.bank_account_holder, bs.status')
            ->inner_join('users u', 'u.id = bs.student_id')
            ->left_join('student_profiles sp', 'sp.user_id = bs.student_id')
            ->where('bs.batch_id', (int) $batchId)
            ->order_by('u.full_name', 'ASC')
            ->get_all();
    }

    /** Opens the next numbered batch for a course. Returns the new id. */
    public function open($courseId, $batchName, $startDate, $endDate, $capacity, $instructorId, $createdBy)
    {
        $last = $this->db->table($this->table)
            ->select_max('batch_number', 'last_number')
            ->where('course_id', (int) $courseId)
            ->get();

        return $this->insert([
            'course_id' => (int) $courseId,
            'instructor_id' => $instructorId ?: null,
            'batch_number' => (int) ($last['last_number'] ?? 0) + 1,
            'batch_name' => $batchName,
            'start_date' => $startDate ?: null,
            'end_date' => $endDate ?: null,
            'capacity' => $capacity,
            'status' => $startDate && $startDate <= date('Y-m-d') ? 'ongoing' : 'upcoming',
            'created_by' => $createdBy,
        ]);
    }

    /** Adds 'enrolled' (active trainees, not dropped) to each batch row. */
    private function attach_enrolled(array $batches)
    {
        if (!$batches) {
            return $batches;
        }
        $rows = $this->db->table('batch_students')
            ->select('batch_id')
            ->select_count('id', 'total')
            ->in('batch_id', array_column($batches, 'id'))
            ->where('status', '!=', 'dropped')
            ->group_by('batch_id')
            ->get_all();
        $counts = array_column($rows, 'total', 'batch_id');
        foreach ($batches as &$batch) {
            $batch['enrolled'] = (int) ($counts[$batch['id']] ?? 0);
        }
        return $batches;
    }
}
