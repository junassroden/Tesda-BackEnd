<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * batch_sessions + batch_attendance: the training days actually held and who
 * attended. Held hours drive a batch's training progress and its automatic
 * completion (see libraries/BatchLifecycle.php).
 */
class BatchSessionModel extends Model
{
    protected $table = 'batch_sessions';
    protected $primary_key = 'id';
    protected $fillable = ['batch_id', 'session_date', 'start_time', 'end_time', 'hours', 'topic', 'status', 'recorded_by'];

    public const MARKS = ['present', 'late', 'absent', 'excused'];

    /** Sessions newest first, each with its attendance counts and marks by student id. */
    public function for_batch($batchId)
    {
        $sessions = $this->db->table('batch_sessions s')
            ->select('s.id, s.session_date, s.start_time, s.end_time, s.hours, s.topic, s.status, s.created_at,
                      u.full_name AS recorded_by_name')
            ->left_join('users u', 'u.id = s.recorded_by')
            ->where('s.batch_id', (int) $batchId)
            ->order_by('s.session_date', 'DESC')
            ->get_all();
        if (!$sessions) {
            return [];
        }
        $marks = $this->db->table('batch_attendance')
            ->select('session_id, student_id, status')
            ->in('session_id', array_column($sessions, 'id'))
            ->get_all();
        $bySession = [];
        foreach ($marks as $m) {
            $bySession[$m['session_id']][(int) $m['student_id']] = $m['status'];
        }
        foreach ($sessions as &$s) {
            $s['hours'] = (float) $s['hours'];
            $s['marks'] = (object) ($bySession[$s['id']] ?? []);
            $counts = array_count_values($bySession[$s['id']] ?? []);
            $s['present'] = (int) ($counts['present'] ?? 0) + (int) ($counts['late'] ?? 0);
            $s['absent'] = (int) ($counts['absent'] ?? 0);
        }
        return $sessions;
    }

    /** One student's attendance across a batch's held sessions, newest first. */
    public function for_student($batchId, $studentId)
    {
        return $this->db->raw(
            "SELECT s.session_date, s.hours, s.topic, COALESCE(a.status, 'not_recorded') AS status
               FROM batch_sessions s
               LEFT JOIN batch_attendance a ON a.session_id = s.id AND a.student_id = ?
              WHERE s.batch_id = ? AND s.status = 'held'
              ORDER BY s.session_date DESC",
            [(int) $studentId, (int) $batchId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Records (or re-records) a day's session and its attendance in one
     * transaction. $marks: [studentId => status] for active trainees only.
     * Returns the session id.
     */
    public function record($batchId, array $session, array $marks, $userId)
    {
        $this->db->transaction();
        try {
            $existing = $this->db->table($this->table)
                ->where(['batch_id' => (int) $batchId, 'session_date' => $session['session_date']])->get();
            $data = array_merge($session, ['batch_id' => (int) $batchId, 'recorded_by' => $userId]);
            if ($existing) {
                $id = (int) $existing['id'];
                $this->update($id, $data);
            } else {
                $id = (int) $this->insert($data);
            }
            $this->db->table('batch_attendance')->where('session_id', $id)->delete();
            if ($session['status'] === 'held') {
                foreach ($marks as $studentId => $status) {
                    $this->db->table('batch_attendance')->insert([
                        'session_id' => $id, 'student_id' => (int) $studentId, 'status' => $status,
                    ]);
                }
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function in_batch($sessionId, $batchId)
    {
        return $this->db->table($this->table)->where(['id' => (int) $sessionId, 'batch_id' => (int) $batchId])->get() ?: null;
    }
}
