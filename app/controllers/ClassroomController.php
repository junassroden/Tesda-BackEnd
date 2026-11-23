<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';
require_once APP_DIR . 'libraries/FileStore.php';

/**
 * Virtual classroom, one per batch (see ClassroomModel).
 *   Teacher (the batch's assigned instructor): posts announcements, shares
 *     materials, creates assignments, reviews and grades submissions.
 *   Student (active in the batch): reads and turns in work.
 *   Completed batches stay readable; nothing can be posted or turned in.
 * Every request re-checks membership on the server.
 */
class ClassroomController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(['instructor', 'student'], 'The virtual classroom is for teachers and students.');
        $this->call->model('BatchModel');
        $this->call->model('ClassroomModel');
        $this->call->model('AuditModel');
    }

    /** GET /classrooms : the user's classrooms. */
    public function index()
    {
        $this->BatchModel->sweep();
        $ids = $this->ClassroomModel->batch_ids_for($this->user_id(), $this->role());
        $counts = $this->ClassroomModel->counts($ids, $this->user_id(), $this->role());
        $rooms = [];
        foreach ($ids as $id) {
            $batch = $this->BatchModel->detail($id);
            if (!$batch) {
                continue;
            }
            $access = $this->ClassroomModel->access($batch, $this->user_id(), $this->role());
            if (!$access) {
                continue;
            }
            $c = $counts[$id] ?? [];
            $rooms[] = [
                'batch' => $batch,
                'read_only' => $access['read_only'],
                'assignments' => (int) ($c['assignments'] ?? 0),
                'to_do' => (int) ($c['to_do'] ?? 0),
                'missing' => (int) ($c['missing'] ?? 0),
                'to_grade' => (int) ($c['to_grade'] ?? 0),
            ];
        }
        $rank = ['ongoing' => 0, 'suspended' => 1, 'upcoming' => 2, 'completed' => 3, 'cancelled' => 4];
        usort($rooms, fn ($a, $b) => ($rank[$a['batch']['status']] ?? 9) <=> ($rank[$b['batch']['status']] ?? 9));
        $this->json(['classrooms' => $rooms]);
    }

    /** GET /classrooms/{batchId} : Stream, Classwork, People and Grades in one load. */
    public function show($batchId)
    {
        [$batch, $access] = $this->room((int) $batchId);
        $isTeacher = $access['role'] === 'teacher';
        $items = $this->ClassroomModel->items($batch['id'], $isTeacher ? null : $this->user_id());
        $people = $this->ClassroomModel->people($batch);
        if (!$isTeacher) {
            // Students see classmates' names only.
            $people['students'] = array_map(fn ($p) => ['id' => $p['id'], 'full_name' => $p['full_name']], $people['students']);
            unset($people['former']);
        }
        $this->json([
            'batch' => $batch,
            'access' => $access,
            'stream' => $this->ClassroomModel->stream($batch['id']),
            'items' => $items,
            'people' => $people,
            'grades' => $isTeacher ? $this->ClassroomModel->submissions_for_batch($batch['id']) : null,
        ]);
    }

    /** POST /classrooms/{batchId}/announcements  { title, body } */
    public function announce($batchId)
    {
        [$batch] = $this->teacher_room((int) $batchId);
        $this->validate([
            'title|Title' => 'max_length[150]',
            'body|Announcement' => 'required|max_length[10000]',
        ]);
        $input = $this->input();
        $body = $this->str($input, 'body');
        $title = $this->str($input, 'title') ?: mb_substr(strtok($body, "\n"), 0, 150);
        $id = $this->ClassroomModel->post($batch, $title, $body, $this->user_id());
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'posted', 'announcement', $id, "Posted in {$batch['batch_name']}: {$title}");
        $this->json(['message' => 'Posted to the class stream.', 'stream' => $this->ClassroomModel->stream($batch['id'])], 201);
    }

    /** DELETE /classrooms/{batchId}/announcements/{id} */
    public function delete_announcement($batchId, $id)
    {
        [$batch] = $this->teacher_room((int) $batchId);
        if (!$this->ClassroomModel->announcement((int) $id, $batch['id'])) {
            $this->json_error('Announcement not found.', 404);
        }
        $this->ClassroomModel->delete_announcement((int) $id);
        $this->json(['message' => 'Announcement deleted.', 'stream' => $this->ClassroomModel->stream($batch['id'])]);
    }

    /**
     * POST /classrooms/{batchId}/items (multipart)
     *   item_type material|assignment, title, topic, instructions, link_url, due_at (YYYY-MM-DDTHH:MM), max_points, files[]
     */
    public function create_item($batchId)
    {
        [$batch] = $this->teacher_room((int) $batchId);
        $this->validate([
            'title|Title' => 'required|max_length[200]',
            'topic|Topic' => 'max_length[100]',
            'instructions|Instructions' => 'max_length[20000]',
            'link_url|Link' => 'max_length[2048]',
            'max_points|Points' => 'numeric|greater_than_equal_to[1]|less_than_equal_to[1000]',
        ]);
        $input = $this->input();
        $type = $this->str($input, 'item_type');
        if (!in_array($type, ['material', 'assignment'], true)) {
            $this->json_error('Choose material or assignment.', 422);
        }
        $link = $this->str($input, 'link_url');
        if ($link !== '') {
            $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));
            if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                $this->json_error('Enter a valid http or https link.', 422);
            }
        }
        $due = null;
        if ($type === 'assignment' && $this->str($input, 'due_at') !== '') {
            $d = DateTime::createFromFormat('Y-m-d\TH:i', $this->str($input, 'due_at')) ?: DateTime::createFromFormat('Y-m-d H:i', $this->str($input, 'due_at'));
            if (!$d) {
                $this->json_error('Enter the due date and time.', 422);
            }
            $due = $d->format('Y-m-d H:i:00');
        }
        $uploads = $this->uploads();
        if ($type === 'material' && !$uploads && $link === '' && $this->str($input, 'instructions') === '') {
            $this->json_error('Attach a file, add a link or write a description for the material.', 422);
        }
        try {
            $id = $this->ClassroomModel->create_item($batch, [
                'item_type' => $type,
                'topic' => $this->str($input, 'topic') ?: null,
                'title' => $this->str($input, 'title'),
                'instructions' => $this->str($input, 'instructions') ?: null,
                'link_url' => $link ?: null,
                'due_at' => $due,
                'max_points' => $type === 'assignment' && $this->str($input, 'max_points') !== '' ? (int) $input['max_points'] : null,
            ], $uploads, $this->user_id());
        } catch (RuntimeException $e) {
            $this->json_error('A file could not be saved. Try again.', 500);
        }
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'created', 'classroom_item', $id,
            ucfirst($type) . " \"{$this->str($input, 'title')}\" added to {$batch['batch_name']}");
        $this->json(['message' => $type === 'assignment' ? 'Assignment created.' : 'Material shared.',
            'items' => $this->ClassroomModel->items($batch['id'])], 201);
    }

    /** DELETE /classroom-items/{id} */
    public function delete_item($itemId)
    {
        $item = $this->item_or_404((int) $itemId);
        [$batch] = $this->teacher_room((int) $item['batch_id']);
        $this->ClassroomModel->delete_item($item['id']);
        $this->AuditModel->log($this->user_id(), $batch['training_center_id'], 'deleted', 'classroom_item', $item['id'],
            "Deleted \"{$item['title']}\" from {$batch['batch_name']}");
        $this->json(['message' => 'Deleted.', 'items' => $this->ClassroomModel->items($batch['id'])]);
    }

    /** GET /classroom-items/{id}/submissions : teacher review list. */
    public function submissions($itemId)
    {
        $item = $this->item_or_404((int) $itemId);
        [$batch, $access] = $this->room((int) $item['batch_id']);
        if ($access['role'] !== 'teacher') {
            $this->json_error('Only the teacher reviews submissions.', 403);
        }
        $this->json(['item' => $item, 'submissions' => $this->ClassroomModel->item_submissions($item), 'read_only' => $access['read_only']]);
    }

    /** POST /classroom-items/{id}/submission (multipart: answer_text, files[]) : the student turns in work. */
    public function submit($itemId)
    {
        $item = $this->item_or_404((int) $itemId);
        [$batch, $access] = $this->room((int) $item['batch_id']);
        if ($access['role'] !== 'student') {
            $this->json_error('Only students turn in work.', 403);
        }
        if ($access['read_only']) {
            $this->json_error('This classroom is closed. Work can no longer be turned in.', 422);
        }
        if ($item['item_type'] !== 'assignment') {
            $this->json_error('Materials do not take submissions.', 422);
        }
        $text = mb_substr($this->str($this->input(), 'answer_text'), 0, 20000);
        try {
            $this->ClassroomModel->submit($item, $this->user_id(), $text, $this->uploads());
        } catch (DomainException $e) {
            $this->json_error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $this->json_error('A file could not be saved. Try again.', 500);
        }
        $this->json(['message' => 'Turned in.', 'items' => $this->ClassroomModel->items($batch['id'], $this->user_id())]);
    }

    /** POST /classroom-submissions/{id}/grade  { grade, feedback } */
    public function grade($submissionId)
    {
        $sub = $this->ClassroomModel->submission((int) $submissionId);
        if (!$sub) {
            $this->json_error('Submission not found.', 404);
        }
        [$batch] = $this->teacher_room((int) $sub['batch_id']);
        $input = $this->input();
        $grade = $this->str($input, 'grade');
        if ($grade === '' || !is_numeric($grade) || (float) $grade < 0) {
            $this->json_error('Enter a grade of 0 or more.', 422);
        }
        if ($sub['max_points'] && (float) $grade > (float) $sub['max_points']) {
            $this->json_error("The grade cannot be more than {$sub['max_points']} points.", 422);
        }
        $feedback = mb_substr($this->str($input, 'feedback'), 0, 5000);
        $this->ClassroomModel->grade($sub['id'], round((float) $grade, 2), $feedback, $this->user_id());
        $this->db->table('notifications')->insert([
            'user_id' => (int) $sub['student_id'],
            'title' => mb_substr("Graded: {$sub['item_title']}", 0, 150),
            'message' => mb_substr("Your work in {$batch['batch_name']} was graded." . ($feedback !== '' ? " Feedback: {$feedback}" : ''), 0, 500),
            'entity_type' => 'classroom_submission',
            'entity_id' => (int) $sub['id'],
        ]);
        $item = $this->ClassroomModel->item($sub['item_id']);
        $this->json(['message' => 'Grade saved and returned to the student.', 'submissions' => $this->ClassroomModel->item_submissions($item)]);
    }

    /** GET /classroom-files/{id}?download=1 */
    public function file($fileId)
    {
        $file = $this->ClassroomModel->file((int) $fileId);
        if (!$file) {
            $this->json_error('File not found.', 404);
        }
        [, $access] = $this->room((int) $file['batch_id']);
        // Submission files: only their student and the teacher.
        if ($file['submission_id'] && $access['role'] === 'student' && (int) $file['submission_student_id'] !== $this->user_id()) {
            $this->json_error('File not found.', 404);
        }
        FileStore::send('classroom', $file['storage_key'], $file['original_name'], $file['mime_type'], isset($_GET['download']));
    }

    /** DELETE /classroom-files/{id} : a student removes a file from work that is not graded yet. */
    public function delete_file($fileId)
    {
        $file = $this->ClassroomModel->file((int) $fileId);
        if (!$file || !$file['submission_id'] || (int) $file['submission_student_id'] !== $this->user_id()) {
            $this->json_error('File not found.', 404);
        }
        [$batch, $access] = $this->room((int) $file['batch_id']);
        if ($access['read_only'] || $file['submission_status'] === 'graded') {
            $this->json_error('Graded or closed work cannot be changed.', 422);
        }
        $this->ClassroomModel->remove_submission_file($file);
        $this->json(['message' => 'File removed.', 'items' => $this->ClassroomModel->items($batch['id'], $this->user_id())]);
    }

    /* ---------------------------------------------------------------- */

    /** [batch, access] or 404 when the user is not in this classroom. */
    private function room($batchId)
    {
        $batch = $this->BatchModel->detail($batchId);
        $access = $batch ? $this->ClassroomModel->access($batch, $this->user_id(), $this->role()) : null;
        if (!$access) {
            $this->json_error('Classroom not found.', 404);
        }
        return [$batch, $access];
    }

    private function teacher_room($batchId)
    {
        [$batch, $access] = $this->room($batchId);
        if ($access['role'] !== 'teacher') {
            $this->json_error('Only the class teacher can do this.', 403);
        }
        if ($access['read_only']) {
            $this->json_error('This batch is in history, so its classroom is read only.', 422);
        }
        return [$batch, $access];
    }

    private function item_or_404($itemId)
    {
        $item = $this->ClassroomModel->item($itemId);
        if (!$item) {
            $this->json_error('Not found.', 404);
        }
        return $item;
    }

    /** Checked classroom uploads from files[] (stops with a 422 on the first problem). */
    private function uploads()
    {
        $uploads = array_values(array_filter(FileStore::uploads('files'),
            fn ($u) => (int) ($u['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if (count($uploads) > 10) {
            $this->json_error('Attach up to 10 files at a time.', 422);
        }
        foreach ($uploads as $upload) {
            $problem = FileStore::problem('classroom', $upload);
            if ($problem) {
                $this->json_error($problem, 422);
            }
        }
        return $uploads;
    }
}
