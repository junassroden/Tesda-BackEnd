<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** course_materials: links a teacher shares with a course. */
class CourseMaterialModel extends Model
{
    protected $table = 'course_materials';
    protected $primary_key = 'id';
    protected $fillable = ['course_id', 'uploaded_by', 'title', 'description', 'resource_url'];

    public function for_course($courseId)
    {
        return $this->filter(['course_id' => (int) $courseId])
            ->order_by('created_at', 'DESC')
            ->get_all();
    }
}
