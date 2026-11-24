<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** allowance_requests (+ allowance_request_students): training allowance per batch and the trainee checklist. */
class AllowanceModel extends Model
{
    protected $table = 'allowance_requests';
    protected $primary_key = 'id';
    protected $fillable = [
        'request_code', 'training_center_id', 'batch_id', 'requested_by', 'amount_per_student', 'status',
        'notes', 'admin_remarks', 'reviewed_by', 'reviewed_at', 'released_at',
    ];

    /** Request columns plus center, batch, and course names. */
    private function with_names()
    {
        return $this->db->table('allowance_requests a')
            ->select('a.id, a.request_code, a.training_center_id, a.batch_id, a.requested_by, a.amount_per_student,
                      a.status, a.notes, a.admin_remarks, a.reviewed_by, a.reviewed_at, a.released_at, a.created_at,
                      tc.name AS center_name, b.batch_name, c.course_name, c.course_code,
                      u.full_name AS requested_by_name, rv.full_name AS reviewed_by_name')
            ->inner_join('training_centers tc', 'tc.id = a.training_center_id')
            ->inner_join('batches b', 'b.id = a.batch_id')
            ->inner_join('courses c', 'c.id = b.course_id')
            ->left_join('users u', 'u.id = a.requested_by')
            ->left_join('users rv', 'rv.id = a.reviewed_by');
    }

    /** All requests (or one center's), newest first, with checklist totals. */
    public function listing($centerId = null, $limit = null)
    {
        $query = $this->with_names();
        if ($centerId) {
            $query->where('a.training_center_id', (int) $centerId);
        }
        $query->order_by('a.id', 'DESC');
        if ($limit) {
            $query->limit((int) $limit);
        }
        return $this->attach_totals($query->get_all());
    }

    public function detail($id)
    {
        return $this->with_names()->where('a.id', (int) $id)->get();
    }

    public function students($requestId)
    {
        return $this->db->table('allowance_request_students s')
            ->select('s.student_id, s.amount, s.bank_account_number, s.bank_account_holder, s.is_checked,
                      s.checked_at, s.released_at, u.full_name, sp.student_number')
            ->inner_join('users u', 'u.id = s.student_id')
            ->left_join('student_profiles sp', 'sp.user_id = s.student_id')
            ->where('s.allowance_request_id', (int) $requestId)
            ->order_by('u.full_name', 'ASC')
            ->get_all();
    }

    /** The batch's open request code, if it has one (pending through approved). */
    public function open_request_for_batch($batchId)
    {
        $row = $this->db->table($this->table)
            ->select('request_code')
            ->where('batch_id', (int) $batchId)
            ->not_in('status', ['rejected', 'released'])
            ->limit(1)
            ->get();
        return $row['request_code'] ?? null;
    }

    /** Creates the request and copies each trainee's bank details. Returns the new id. */
    public function submit($centerId, $userId, $batchId, $amount, $notes)
    {
        $trainees = $this->db->table('batch_students bs')
            ->select('bs.student_id, sp.bank_account_number, sp.bank_account_holder')
            ->left_join('student_profiles sp', 'sp.user_id = bs.student_id')
            ->where('bs.batch_id', (int) $batchId)
            ->where('bs.status', '!=', 'dropped')
            ->get_all();
        if (!$trainees) {
            throw new RuntimeException('That batch has no trainees yet.');
        }

        $this->db->transaction();
        try {
            $id = $this->insert([
                'request_code' => $this->next_code(),
                'training_center_id' => $centerId,
                'batch_id' => $batchId,
                'requested_by' => $userId,
                'amount_per_student' => $amount,
                'status' => 'pending',
                'notes' => $notes !== '' ? $notes : null,
            ]);
            foreach ($trainees as $t) {
                $this->db->table('allowance_request_students')->insert([
                    'allowance_request_id' => $id,
                    'student_id' => $t['student_id'],
                    'amount' => $amount,
                    'bank_account_number' => $t['bank_account_number'],
                    'bank_account_holder' => $t['bank_account_holder'],
                ]);
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function set_checked($requestId, $studentId, $checked, $userId)
    {
        return $this->db->table('allowance_request_students')
            ->where(['allowance_request_id' => (int) $requestId, 'student_id' => (int) $studentId])
            ->update([
                'is_checked' => $checked ? 1 : 0,
                'checked_by' => $checked ? $userId : null,
                'checked_at' => $checked ? date('Y-m-d H:i:s') : null,
            ]);
    }

    public function unchecked_count($requestId)
    {
        return (int) $this->db->table('allowance_request_students')
            ->where(['allowance_request_id' => (int) $requestId, 'is_checked' => 0])
            ->count();
    }

    public function review($id, $status, $remarks, $reviewerId)
    {
        return $this->update($id, [
            'status' => $status,
            'admin_remarks' => $remarks !== '' ? $remarks : null,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Mark released for every trainee and chain it on the ledger. All or nothing. */
    public function release($id, $userId, AuditModel $audit)
    {
        $request = $this->find($id);
        $now = date('Y-m-d H:i:s');

        $this->db->transaction();
        try {
            $this->update($id, ['status' => 'released', 'released_at' => $now]);
            $this->db->table('allowance_request_students')
                ->where('allowance_request_id', (int) $id)
                ->update(['released_at' => $now]);

            $rows = $this->db->table('allowance_request_students')
                ->select('student_id, amount, bank_account_number')
                ->where('allowance_request_id', (int) $id)
                ->order_by('student_id', 'ASC')
                ->get_all();

            $audit->record('allowance_request', (int) $id, 'released', $userId);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** [status => ['count' => n, 'amount' => total pesos]] */
    public function status_totals($centerId = null)
    {
        $counts = $this->db->table($this->table)->select('status')->select_count('id', 'total');
        if ($centerId) {
            $counts->where('training_center_id', (int) $centerId);
        }
        $countRows = $counts->group_by('status')->get_all();

        $amounts = $this->db->table('allowance_request_students s')
            ->select('a.status')
            ->select_sum('s.amount', 'amount')
            ->inner_join('allowance_requests a', 'a.id = s.allowance_request_id');
        if ($centerId) {
            $amounts->where('a.training_center_id', (int) $centerId);
        }
        $amountByStatus = array_column($amounts->group_by('a.status')->get_all(), 'amount', 'status');

        $totals = [];
        foreach ($countRows as $row) {
            $totals[$row['status']] = [
                'count' => (int) $row['total'],
                'amount' => (float) ($amountByStatus[$row['status']] ?? 0),
            ];
        }
        return $totals;
    }

    /** ALW-0001, ALW-0002, ... */
    private function next_code()
    {
        $row = $this->db->table($this->table)->select_max('id', 'last_id')->get();
        return sprintf('ALW-%04d', (int) ($row['last_id'] ?? 0) + 1);
    }

    /** Adds student_count, checked_count, total_amount to each request row. */
    private function attach_totals(array $requests)
    {
        if (!$requests) {
            return $requests;
        }
        $rows = $this->db->table('allowance_request_students')
            ->select('allowance_request_id')
            ->select_count('id', 'student_count')
            ->select_sum('is_checked', 'checked_count')
            ->select_sum('amount', 'total_amount')
            ->in('allowance_request_id', array_column($requests, 'id'))
            ->group_by('allowance_request_id')
            ->get_all();
        $totals = array_column($rows, null, 'allowance_request_id');
        foreach ($requests as &$request) {
            $t = $totals[$request['id']] ?? [];
            $request['student_count'] = (int) ($t['student_count'] ?? 0);
            $request['checked_count'] = (int) ($t['checked_count'] ?? 0);
            $request['total_amount'] = (float) ($t['total_amount'] ?? 0);
        }
        return $requests;
    }
}
