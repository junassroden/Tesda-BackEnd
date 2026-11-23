<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Student records of a learning center: users (role student) + student_profiles.
 * One profile per person per center; enrollment always reuses it.
 */
class StudentRecordModel extends Model
{
    protected $table = 'student_profiles';
    protected $primary_key = 'user_id';
    protected $fillable = [
        'user_id', 'student_number', 'first_name', 'middle_name', 'last_name', 'birthdate', 'sex', 'civil_status',
        'highest_education', 'address', 'guardian_name', 'guardian_contact', 'bank_name', 'bank_account_number',
        'bank_account_holder', 'created_by',
    ];

    public const PROFILE_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'birthdate', 'sex', 'civil_status', 'highest_education',
        'address', 'guardian_name', 'guardian_contact',
    ];

    private function base()
    {
        return $this->db->table('users u')
            ->select('u.id, u.username, u.full_name, u.email, u.contact_number, u.is_active, u.created_at, u.training_center_id,
                      sp.student_number, sp.first_name, sp.middle_name, sp.last_name, sp.birthdate, sp.sex,
                      sp.civil_status, sp.highest_education, sp.address, sp.guardian_name, sp.guardian_contact,
                      sp.bank_name, sp.bank_account_number, sp.bank_account_holder,
                      tc.name AS training_center_name, tc.center_code, tc.address AS center_address')
            ->left_join('student_profiles sp', 'sp.user_id = u.id')
            ->left_join('training_centers tc', 'tc.id = u.training_center_id')
            ->where('u.role', 'student');
    }

    /** Students of a center. q matches name, email, username or student number. */
    public function search($centerId, $q = '', $limit = 500)
    {
        $query = $this->base()->where('u.training_center_id', (int) $centerId);
        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->grouped(function ($w) use ($like) {
                $w->like('u.full_name', $like)->or_like('u.email', $like)
                  ->or_like('u.username', $like)->or_like('sp.student_number', $like);
            });
        }
        $rows = $query->order_by('u.full_name', 'ASC')->limit((int) $limit)->get_all();
        if (!$rows) {
            return [];
        }
        // Current batch (active), and how many batches the student has ever been in.
        $memberships = $this->db->table('batch_students bs')
            ->select('bs.student_id, bs.status, b.batch_name, c.course_name, c.course_code')
            ->inner_join('batches b', 'b.id = bs.batch_id')
            ->inner_join('courses c', 'c.id = b.course_id')
            ->in('bs.student_id', array_column($rows, 'id'))
            ->get_all();
        $by = [];
        foreach ($memberships as $m) {
            $by[$m['student_id']][] = $m;
        }
        foreach ($rows as &$row) {
            $list = $by[$row['id']] ?? [];
            $active = array_values(array_filter($list, fn ($m) => $m['status'] === 'active'));
            $row['active_batches'] = array_map(fn ($m) => $m['course_code'] . ' · ' . $m['batch_name'], $active);
            $row['batch_count'] = count($list);
        }
        return $rows;
    }

    /** One student of a center, or null. */
    public function find_in_center($studentId, $centerId)
    {
        return $this->base()->where(['u.id' => (int) $studentId, 'u.training_center_id' => (int) $centerId])->get() ?: null;
    }

    /** The student's own record (any center). */
    public function own($studentId)
    {
        return $this->base()->where('u.id', (int) $studentId)->get() ?: null;
    }

    /** Every batch the student was ever in, newest first, with status and reason. */
    public function enrollment_history($studentId, $centerId)
    {
        return $this->db->table('batch_students bs')
            ->select('bs.batch_id, bs.status AS membership_status, bs.assigned_at, bs.ended_at, bs.end_reason,
                      b.batch_name, b.batch_number, b.course_id, b.start_date, b.end_date, b.status AS batch_status,
                      c.course_name, c.course_code, c.qualification, u.full_name AS instructor_name')
            ->inner_join('batches b', 'b.id = bs.batch_id')
            ->inner_join('courses c', 'c.id = b.course_id')
            ->left_join('users u', 'u.id = b.instructor_id')
            ->where(['bs.student_id' => (int) $studentId, 'c.training_center_id' => (int) $centerId])
            ->order_by('bs.assigned_at', 'DESC')
            ->get_all();
    }

    /** Students at the center with the same name (and birthdate, when given). */
    public function possible_duplicates($centerId, $first, $last, $birthdate)
    {
        $query = $this->base()
            ->where('u.training_center_id', (int) $centerId)
            ->where('sp.first_name', $first)
            ->where('sp.last_name', $last);
        if ($birthdate) {
            $query->where('sp.birthdate', $birthdate);
        }
        return $query->get_all();
    }

    /** Creates the login account and the profile together. Returns the new user id. */
    public function create_student(array $center, array $profile, $email, $contact, $password, $createdBy)
    {
        $this->db->transaction();
        try {
            // Work these out first: the query builder is shared, so it cannot be used inside another chain.
            $fullName = self::full_name($profile);
            $username = $this->free_username($email, $profile);
            $studentNumber = $this->next_student_number($center);
            $this->db->table('users')->insert([
                'username' => $username,
                'full_name' => $fullName,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'student',
                'training_center_id' => (int) $center['id'],
                'contact_number' => $contact ?: null,
                'is_active' => 1,
            ]);
            $userId = (int) $this->db->last_id();
            $this->db->table('student_profiles')->insert(array_merge($profile, [
                'user_id' => $userId,
                'student_number' => $studentNumber,
                'created_by' => $createdBy,
            ]));
            $this->db->commit();
            return $userId;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** Updates profile fields (creating the profile row if an older account has none) and the account name. */
    public function save_profile($studentId, array $center, array $profile, array $account)
    {
        $this->db->transaction();
        try {
            $exists = $this->db->table('student_profiles')->where('user_id', (int) $studentId)->get();
            if ($exists) {
                $this->db->table('student_profiles')->where('user_id', (int) $studentId)->update($profile);
            } else {
                $studentNumber = $this->next_student_number($center);
                $this->db->table('student_profiles')->insert(array_merge($profile, [
                    'user_id' => (int) $studentId,
                    'student_number' => $studentNumber,
                ]));
            }
            if (!empty($profile['first_name']) && !empty($profile['last_name'])) {
                $account['full_name'] = self::full_name($profile);
            }
            if ($account) {
                $this->db->table('users')->where('id', (int) $studentId)->update($account);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public static function full_name(array $p)
    {
        return trim(preg_replace('/\s+/', ' ', ($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? '') . ' ' . ($p['last_name'] ?? '')));
    }

    /** e.g. 2026-ORM-0005: year, the center code without "LC-" (3 letters), then a running number. */
    private function next_student_number(array $center)
    {
        $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', preg_replace('/^LC-/i', '', (string) $center['center_code'])), 0, 3)) ?: 'STU';
        $prefix = date('Y') . '-' . $code . '-';
        $last = $this->db->raw(
            'SELECT MAX(CAST(SUBSTRING(student_number, ?) AS UNSIGNED)) AS n FROM student_profiles WHERE student_number LIKE ?',
            [strlen($prefix) + 1, $prefix . '%']
        )->fetch(PDO::FETCH_ASSOC);
        return $prefix . str_pad((string) ((int) ($last['n'] ?? 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    private function free_username($email, array $profile)
    {
        $base = strtolower(preg_replace('/[^a-z0-9.]+/i', '', strstr($email, '@', true) ?: ($profile['first_name'] . '.' . $profile['last_name'])));
        $base = substr($base ?: 'student', 0, 90);
        $name = $base;
        for ($i = 2; $this->db->table('users')->where('username', $name)->get(); $i++) {
            $name = $base . $i;
        }
        return $name;
    }
}
