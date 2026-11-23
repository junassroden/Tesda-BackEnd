<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/FileStore.php';

/**
 * The virtual classroom of a batch: Stream (announcements), Classwork
 * (classroom_items: materials and assignments), People and Grades
 * (classroom_submissions). Files live in classroom_files.
 *
 * Membership is derived, never stored:
 *   teacher   batches.instructor_id
 *   students  batch_students with status active (full access) or completed (read only)
 * Dropped or transferred trainees lose access; their submissions stay on record.
 */
class ClassroomModel extends Model
{
    protected $table = 'classroom_items';
    protected $primary_key = 'id';
    protected $fillable = ['batch_id', 'item_type', 'topic', 'title', 'instructions', 'link_url', 'due_at', 'max_points', 'created_by'];

    /**
     * What the user may do in a batch's classroom, or null for no access.
     * Returns ['role' => teacher|student, 'read_only' => bool, 'membership' => status|null].
     */
    public function access(array $batch, $userId, $role)
    {
        $readOnly = in_array($batch['status'], ['completed', 'cancelled'], true);
        if ($role === 'instructor' && (int) $batch['instructor_id'] === (int) $userId) {
            return ['role' => 'teacher', 'read_only' => $readOnly, 'membership' => null];
        }
        if ($role === 'student') {
            $m = $this->db->table('batch_students')->select('status')
                ->where(['batch_id' => (int) $batch['id'], 'student_id' => (int) $userId])->get();
            if ($m && in_array($m['status'], ['active', 'completed'], true)) {
                return ['role' => 'student', 'read_only' => $readOnly || $m['status'] === 'completed', 'membership' => $m['status']];
            }
        }
        return null;
    }

    /** Batch ids whose classroom the user belongs to. */
    public function batch_ids_for($userId, $role)
    {
        if ($role === 'instructor') {
            $rows = $this->db->table('batches')->select('id')->where('instructor_id', (int) $userId)->get_all();
        } else {
            $rows = $this->db->table('batch_students')->select('batch_id AS id')
                ->where('student_id', (int) $userId)->in('status', ['active', 'completed'])->get_all();
        }
        return array_map('intval', array_column($rows, 'id'));
    }

    /** Per classroom: assignments, and for a student how many are still to do; for a teacher how many await grading. */
    public function counts(array $batchIds, $userId, $role)
    {
        if (!$batchIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $batchIds));
        if ($role === 'student') {
            $rows = $this->db->raw(
                "SELECT i.batch_id, COUNT(*) AS assignments,
                        SUM(s.id IS NULL) AS to_do,
                        SUM(s.id IS NULL AND i.due_at IS NOT NULL AND i.due_at < NOW()) AS missing
                   FROM classroom_items i
                   LEFT JOIN classroom_submissions s ON s.item_id = i.id AND s.student_id = ?
                  WHERE i.item_type = 'assignment' AND i.batch_id IN ($in)
                  GROUP BY i.batch_id",
                [(int) $userId]
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $this->db->raw(
                "SELECT i.batch_id, COUNT(DISTINCT i.id) AS assignments,
                        SUM(s.status = 'submitted') AS to_grade
                   FROM classroom_items i
                   LEFT JOIN classroom_submissions s ON s.item_id = i.id
                  WHERE i.item_type = 'assignment' AND i.batch_id IN ($in)
                  GROUP BY i.batch_id"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        return array_column($rows, null, 'batch_id');
    }

    /** Announcements of the classroom, newest first. */
    public function stream($batchId)
    {
        return $this->db->table('announcements a')
            ->select('a.id, a.title, a.body, a.published_at, a.posted_by, u.full_name AS posted_by_name, u.role AS posted_by_role')
            ->left_join('users u', 'u.id = a.posted_by')
            ->where(['a.batch_id' => (int) $batchId, 'a.is_published' => 1])
            ->order_by('a.published_at', 'DESC')
            ->get_all();
    }

    public function post(array $batch, $title, $body, $userId)
    {
        $this->db->table('announcements')->insert([
            'training_center_id' => (int) $batch['training_center_id'],
            'batch_id' => (int) $batch['id'],
            'posted_by' => $userId,
            'title' => $title,
            'body' => $body,
            'is_published' => 1,
            'published_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->last_id();
    }

    public function announcement($id, $batchId)
    {
        return $this->db->table('announcements')->where(['id' => (int) $id, 'batch_id' => (int) $batchId])->get() ?: null;
    }

    public function delete_announcement($id)
    {
        return $this->db->table('announcements')->where('id', (int) $id)->delete();
    }

    /**
     * Classwork, newest first, with attachments. For a student each assignment
     * carries their submission; for a teacher, submitted/graded counts.
     */
    public function items($batchId, $studentId = null)
    {
        $items = $this->db->table('classroom_items i')
            ->select('i.id, i.item_type, i.topic, i.title, i.instructions, i.link_url, i.due_at, i.max_points,
                      i.created_at, i.updated_at, u.full_name AS created_by_name')
            ->left_join('users u', 'u.id = i.created_by')
            ->where('i.batch_id', (int) $batchId)
            ->order_by('i.created_at', 'DESC')
            ->get_all();
        if (!$items) {
            return [];
        }
        $ids = array_column($items, 'id');
        $files = $this->files_by('item_id', $ids);

        $subs = [];
        if ($studentId) {
            $rows = $this->db->table('classroom_submissions')
                ->select('id, item_id, status, answer_text, submitted_at, is_late, grade, feedback, graded_at')
                ->in('item_id', $ids)->where('student_id', (int) $studentId)->get_all();
            $subFiles = $this->files_by('submission_id', array_column($rows, 'id'));
            foreach ($rows as $row) {
                $row['files'] = $subFiles[$row['id']] ?? [];
                $subs[$row['item_id']] = $row;
            }
        } else {
            $rows = $this->db->table('classroom_submissions')
                ->select('item_id')
                ->select_count('id', 'turned_in')
                ->in('item_id', $ids)
                ->group_by('item_id')
                ->get_all();
            $graded = $this->db->table('classroom_submissions')
                ->select('item_id')
                ->select_count('id', 'graded')
                ->in('item_id', $ids)->where('status', 'graded')
                ->group_by('item_id')
                ->get_all();
            $turned = array_column($rows, 'turned_in', 'item_id');
            $gradedBy = array_column($graded, 'graded', 'item_id');
        }

        foreach ($items as &$item) {
            $item['files'] = $files[$item['id']] ?? [];
            if ($studentId) {
                $item['submission'] = $subs[$item['id']] ?? null;
            } else {
                $item['turned_in'] = (int) ($turned[$item['id']] ?? 0);
                $item['graded'] = (int) ($gradedBy[$item['id']] ?? 0);
            }
        }
        return $items;
    }

    public function item($itemId)
    {
        return $this->db->table('classroom_items')->where('id', (int) $itemId)->get() ?: null;
    }

    /** Teacher, current members (active, completed) and former members (dropped, transferred). */
    public function people(array $batch)
    {
        $members = $this->db->table('batch_students bs')
            ->select('u.id, u.full_name, u.email, sp.student_number, bs.status')
            ->inner_join('users u', 'u.id = bs.student_id')
            ->left_join('student_profiles sp', 'sp.user_id = bs.student_id')
            ->where('bs.batch_id', (int) $batch['id'])
            ->order_by('u.full_name', 'ASC')
            ->get_all();
        return [
            'teacher' => $batch['instructor_id'] ? ['id' => (int) $batch['instructor_id'], 'full_name' => $batch['instructor_name'], 'email' => $batch['instructor_email']] : null,
            'students' => array_values(array_filter($members, fn ($m) => in_array($m['status'], ['active', 'completed'], true))),
            'former' => array_values(array_filter($members, fn ($m) => in_array($m['status'], ['dropped', 'transferred'], true))),
        ];
    }

    /** Every submission of the classroom's assignments (teacher's grade sheet). */
    public function submissions_for_batch($batchId)
    {
        return $this->db->table('classroom_submissions s')
            ->select('s.id, s.item_id, s.student_id, s.status, s.submitted_at, s.is_late, s.grade')
            ->inner_join('classroom_items i', 'i.id = s.item_id')
            ->where('i.batch_id', (int) $batchId)
            ->get_all();
    }

    /** Members and their submission for one assignment (teacher review). */
    public function item_submissions(array $item)
    {
        $rows = $this->db->table('batch_students bs')
            ->select('u.id AS student_id, u.full_name, sp.student_number, bs.status AS membership_status,
                      s.id, s.status, s.answer_text, s.submitted_at, s.is_late, s.grade, s.feedback, s.graded_at')
            ->inner_join('users u', 'u.id = bs.student_id')
            ->left_join('student_profiles sp', 'sp.user_id = bs.student_id')
            ->left_join('classroom_submissions s', 's.student_id = bs.student_id AND s.item_id = ' . (int) $item['id'])
            ->where('bs.batch_id', (int) $item['batch_id'])
            ->order_by('u.full_name', 'ASC')
            ->get_all();
        // Former members appear only if they turned something in (their work stays on record).
        $rows = array_values(array_filter($rows, fn ($r) => in_array($r['membership_status'], ['active', 'completed'], true) || $r['id']));
        $files = $this->files_by('submission_id', array_values(array_filter(array_column($rows, 'id'))));
        foreach ($rows as &$row) {
            $row['files'] = $row['id'] ? ($files[$row['id']] ?? []) : [];
        }
        return $rows;
    }

    public function submission($id)
    {
        return $this->db->table('classroom_submissions s')
            ->select('s.id, s.item_id, s.student_id, s.status, s.answer_text, s.submitted_at, s.is_late, s.grade, s.feedback,
                      i.batch_id, i.max_points, i.title AS item_title')
            ->inner_join('classroom_items i', 'i.id = s.item_id')
            ->where('s.id', (int) $id)
            ->get() ?: null;
    }

    /** Creates a material or assignment with its attachments (already checked). */
    public function create_item(array $batch, array $data, array $uploads, $userId)
    {
        $saved = [];
        $this->db->transaction();
        try {
            $id = (int) $this->insert(array_merge($data, ['batch_id' => (int) $batch['id'], 'created_by' => $userId]));
            foreach ($uploads as $upload) {
                $file = FileStore::save('classroom', $upload);
                $saved[] = $file;
                $this->insert_file($batch['id'], $file, $userId, $id, null);
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->roll_back();
            $this->discard($saved);
            throw $e;
        }
    }

    /** Deletes an item; its files and submissions go with it (the rows cascade). */
    public function delete_item($itemId)
    {
        $keys = $this->db->raw(
            'SELECT storage_key FROM classroom_files WHERE item_id = ?
             UNION SELECT f.storage_key FROM classroom_files f INNER JOIN classroom_submissions s ON s.id = f.submission_id WHERE s.item_id = ?',
            [(int) $itemId, (int) $itemId]
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->delete((int) $itemId);
        foreach ($keys as $key) {
            FileStore::remove('classroom', $key);
        }
    }

    /** Turns in (or adds to) a student's work. Graded work is locked. */
    public function submit(array $item, $studentId, $text, array $uploads)
    {
        $existing = $this->db->table('classroom_submissions')
            ->where(['item_id' => (int) $item['id'], 'student_id' => (int) $studentId])->get();
        if ($existing && $existing['status'] === 'graded') {
            throw new DomainException('This work was already graded.');
        }
        if (!$existing && $text === '' && !$uploads) {
            throw new DomainException('Attach a file or write your answer before turning in.');
        }
        $late = $item['due_at'] && date('Y-m-d H:i:s') > $item['due_at'] ? 1 : 0;
        $saved = [];
        $this->db->transaction();
        try {
            if ($existing) {
                $id = (int) $existing['id'];
                $this->db->table('classroom_submissions')->where('id', $id)->update([
                    'answer_text' => $text !== '' ? $text : $existing['answer_text'],
                    'submitted_at' => date('Y-m-d H:i:s'),
                    'is_late' => $late,
                ]);
            } else {
                $this->db->table('classroom_submissions')->insert([
                    'item_id' => (int) $item['id'], 'student_id' => (int) $studentId, 'status' => 'submitted',
                    'answer_text' => $text !== '' ? $text : null, 'submitted_at' => date('Y-m-d H:i:s'), 'is_late' => $late,
                ]);
                $id = (int) $this->db->last_id();
            }
            foreach ($uploads as $upload) {
                $file = FileStore::save('classroom', $upload);
                $saved[] = $file;
                $this->insert_file($item['batch_id'], $file, $studentId, null, $id);
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->roll_back();
            $this->discard($saved);
            throw $e;
        }
    }

    public function grade($submissionId, $grade, $feedback, $userId)
    {
        return $this->db->table('classroom_submissions')->where('id', (int) $submissionId)->update([
            'status' => 'graded',
            'grade' => $grade,
            'feedback' => $feedback !== '' ? $feedback : null,
            'graded_by' => $userId,
            'graded_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** A classroom file with what it belongs to (for access checks). */
    public function file($fileId)
    {
        return $this->db->table('classroom_files f')
            ->select('f.id, f.batch_id, f.item_id, f.submission_id, f.original_name, f.storage_key, f.mime_type, f.file_size,
                      f.uploaded_by, s.student_id AS submission_student_id, s.status AS submission_status')
            ->left_join('classroom_submissions s', 's.id = f.submission_id')
            ->where('f.id', (int) $fileId)
            ->get() ?: null;
    }

    /** Removes one of a student's own files from work that is not graded yet. */
    public function remove_submission_file(array $file)
    {
        $this->db->table('classroom_files')->where('id', (int) $file['id'])->delete();
        FileStore::remove('classroom', $file['storage_key']);
    }

    private function insert_file($batchId, array $file, $userId, $itemId, $submissionId)
    {
        // Exactly one owner: an item or a submission (MySQL cannot CHECK this on cascading FK columns).
        if (($itemId === null) === ($submissionId === null)) {
            throw new LogicException('A classroom file belongs to exactly one item or submission.');
        }
        $this->db->table('classroom_files')->insert([
            'batch_id' => (int) $batchId,
            'item_id' => $itemId,
            'submission_id' => $submissionId,
            'original_name' => $file['original_name'],
            'storage_key' => $file['storage_key'],
            'mime_type' => $file['mime_type'],
            'file_size' => $file['file_size'],
            'uploaded_by' => $userId,
        ]);
    }

    private function files_by($column, array $ids)
    {
        if (!$ids) {
            return [];
        }
        $rows = $this->db->table('classroom_files')
            ->select("id, {$column} AS owner_id, original_name, mime_type, file_size, created_at")
            ->in($column, $ids)
            ->order_by('id', 'ASC')
            ->get_all();
        $by = [];
        foreach ($rows as $row) {
            $by[$row['owner_id']][] = $row;
        }
        return $by;
    }

    private function discard(array $saved)
    {
        foreach ($saved as $file) {
            FileStore::remove('classroom', $file['storage_key']);
        }
    }
}
