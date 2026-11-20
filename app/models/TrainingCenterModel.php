<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** training_centers: the main office (TESDA-MAIN) and every learning center. */
class TrainingCenterModel extends Model
{
    protected $table = 'training_centers';
    protected $primary_key = 'id';
    protected $fillable = [
        'center_code', 'name', 'center_type', 'region', 'province', 'city',
        'address', 'contact_email', 'contact_phone', 'is_active',
    ];

    const MAIN_OFFICE_CODE = 'TESDA-MAIN';

    public function all_active()
    {
        return $this->filter(['is_active' => 1])->order_by('name', 'ASC')->get_all();
    }

    public function find_active($id)
    {
        return $this->filter(['id' => (int) $id, 'is_active' => 1])->get();
    }

    public function main_office()
    {
        return $this->filter(['center_code' => self::MAIN_OFFICE_CODE, 'is_active' => 1])->get();
    }

    public function is_main_office($id)
    {
        $center = $this->find_active($id);
        return ($center['center_code'] ?? '') === self::MAIN_OFFICE_CODE;
    }

    public function code_taken($code)
    {
        return $this->exists(['center_code' => $code]);
    }

    /** Every center with how many users and courses it has. */
    public function all_with_counts()
    {
        $users = $this->counts_by_center('users');
        $courses = $this->counts_by_center('courses');

        $centers = $this->query()->order_by('name', 'ASC')->get_all();
        foreach ($centers as &$center) {
            $center['user_count'] = $users[$center['id']] ?? 0;
            $center['course_count'] = $courses[$center['id']] ?? 0;
        }
        return $centers;
    }

    /** [center_id => row count] for a table with a training_center_id column. */
    private function counts_by_center($table)
    {
        $rows = $this->db->table($table)
            ->select('training_center_id')
            ->select_count('id', 'total')
            ->where_not_null('training_center_id')
            ->group_by('training_center_id')
            ->get_all();
        return array_map('intval', array_column($rows, 'total', 'training_center_id'));
    }
}
