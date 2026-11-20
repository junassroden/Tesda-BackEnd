<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** enrollments: which student is enrolled in which course, and with which teacher. */
class EnrollmentModel extends Model
{
    protected $table = 'enrollments';
    protected $primary_key = 'id';
    protected $fillable = ['student_id', 'course_id', 'instructor_id', 'status'];

    /** A student's active enrollments, with their place in each course's list. */
    public function for_student($studentId)
    {
        $rows = $this->db->table('enrollments e')
            ->select('e.id, e.course_id, e.status AS enrollment_status, e.enrolled_at,
                      c.course_code, c.course_name, c.qualification, c.description,
                      c.class_days, c.class_schedule, c.course_outline, c.training_center_id,
                      tc.name AS training_center_name, tc.region, tc.province, tc.city')
            ->inner_join('courses c', 'c.id = e.course_id')
            ->inner_join('training_centers tc', 'tc.id = c.training_center_id')
            ->where(['e.student_id' => (int) $studentId, 'e.status' => 'enrolled'])
            ->order_by('e.enrolled_at', 'DESC')
            ->get_all();
        if (!$rows) {
            return [];
        }

        // Everyone enrolled in those courses, oldest first, to work out each position.
        $classmates = $this->db->table($this->table)
            ->select('id, course_id')
            ->in('course_id', array_column($rows, 'course_id'))
            ->where('status', 'enrolled')
            ->order_by('enrolled_at', 'ASC')
            ->get_all();
        $byCourse = [];
        foreach ($classmates as $c) {
            $byCourse[$c['course_id']][] = (int) $c['id'];
        }

        foreach ($rows as &$row) {
            $ids = $byCourse[$row['course_id']] ?? [];
            $row['course_student_count'] = count($ids);
            $row['course_position'] = array_search((int) $row['id'], $ids, true) + 1;
            unset($row['id']);
        }
        return $rows;
    }

    /** Students a teacher enrolled at their center. */
    public function for_instructor($instructorId, $centerId)
    {
        return $this->db->table('enrollments e')
            ->select('e.id, e.status, e.enrolled_at, c.id AS course_id, c.course_code, c.course_name, c.qualification,
                      tc.name AS training_center_name, u.full_name, u.username, u.email')
            ->inner_join('courses c', 'c.id = e.course_id')
            ->inner_join('training_centers tc', 'tc.id = c.training_center_id')
            ->inner_join('users u', 'u.id = e.student_id')
            ->where(['c.training_center_id' => (int) $centerId, 'e.instructor_id' => (int) $instructorId, 'e.status' => 'enrolled'])
            ->order_by('e.enrolled_at', 'DESC')
            ->get_all();
    }

    public function roster_for_course($courseId)
    {
        return $this->db->table('enrollments e')
            ->select('u.full_name, u.username, u.email, e.enrolled_at, e.status')
            ->inner_join('users u', 'u.id = e.student_id')
            ->where(['e.course_id' => (int) $courseId, 'e.status' => 'enrolled'])
            ->order_by('e.enrolled_at', 'ASC')
            ->get_all();
    }

    public function enrolled_count($courseId)
    {
        return (int) $this->db->table($this->table)
            ->where(['course_id' => (int) $courseId, 'status' => 'enrolled'])
            ->count();
    }
}
