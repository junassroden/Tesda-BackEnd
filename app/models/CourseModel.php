<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** courses: the NC programs a learning center offers. */
class CourseModel extends Model
{
    protected $table = 'courses';
    protected $primary_key = 'id';
    protected $fillable = [
        'training_center_id', 'course_code', 'course_name', 'qualification', 'description',
        'class_days', 'class_schedule', 'course_outline', 'duration_hours', 'capacity', 'status',
    ];

    /** Course columns plus the center's name and location. */
    private function with_center()
    {
        return $this->db->table('courses c')
            ->select('c.id, c.training_center_id, c.course_code, c.course_name, c.qualification, c.description,
                      c.class_days, c.class_schedule, c.course_outline, c.duration_hours, c.capacity, c.status, c.created_at,
                      tc.name AS training_center_name, tc.region, tc.province, tc.city, tc.address')
            ->inner_join('training_centers tc', 'tc.id = c.training_center_id');
    }

    /** All courses (or one center's) with their enrolled-student count. */
    public function listing($centerId = null)
    {
        $query = $this->with_center();
        if ($centerId !== null) {
            $query->where('c.training_center_id', (int) $centerId);
        }
        $courses = $query->order_by('c.course_name', 'ASC')->get_all();
        return $this->attach_enrollment_counts($courses);
    }

    /** One course with center details, optionally limited to a center. */
    public function detail($id, $centerId = null)
    {
        $query = $this->with_center()->where('c.id', (int) $id);
        if ($centerId !== null) {
            $query->where('c.training_center_id', (int) $centerId);
        }
        $course = $query->get();
        return $course ? $this->attach_enrollment_counts([$course])[0] : null;
    }

    /** Open courses of a center (for enrolling students). */
    public function open($centerId)
    {
        return $this->filter(['status' => 'open', 'training_center_id' => (int) $centerId])
            ->order_by('course_name', 'ASC')
            ->get_all();
    }

    /** The course if it belongs to the center. */
    public function in_center($id, $centerId)
    {
        return $this->filter(['id' => (int) $id, 'training_center_id' => (int) $centerId])->get();
    }

    public function code_taken($code, $centerId, $exceptId = 0)
    {
        return (bool) $this->db->table($this->table)
            ->where(['course_code' => $code, 'training_center_id' => (int) $centerId])
            ->where('id', '!=', (int) $exceptId)
            ->get();
    }

    private function attach_enrollment_counts(array $courses)
    {
        if (!$courses) {
            return $courses;
        }
        $rows = $this->db->table('enrollments')
            ->select('course_id')
            ->select_count('id', 'total')
            ->in('course_id', array_column($courses, 'id'))
            ->where('status', 'enrolled')
            ->group_by('course_id')
            ->get_all();
        $counts = array_column($rows, 'total', 'course_id');
        foreach ($courses as &$course) {
            $course['enrollment_count'] = (int) ($counts[$course['id']] ?? 0);
        }
        return $courses;
    }
}
