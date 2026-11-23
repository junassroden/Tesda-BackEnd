<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';
require_once APP_DIR . 'libraries/FileStore.php';

/**
 * Enrollment documents (see StudentDocumentModel).
 *
 * Who can do what, checked here on every request:
 *   student       sees, uploads and withdraws copies of their OWN documents
 *   center_admin  sees, uploads for, and reviews students of THEIR center only
 *   others        no access (teachers and the main office never see personal documents)
 * Files are stored outside the web root and only streamed after these checks.
 */
class StudentDocumentController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(['student', 'center_admin'], 'Student documents are only available to the student and their learning center.');
        $this->call->model('StudentDocumentModel');
        $this->call->model('StudentRecordModel');
        $this->call->model('AuditModel');
    }

    /** GET /student/documents : the signed-in student's checklist. */
    public function mine()
    {
        $student = $this->self_student();
        $this->json($this->checklist_payload($student));
    }

    /** POST /student/documents/{typeId} (multipart files[]) */
    public function upload_mine($typeId)
    {
        $student = $this->self_student();
        $this->store($student, (int) $typeId);
        $this->json(array_merge(['message' => 'Uploaded. The learning center will review it.'], $this->checklist_payload($student)), 201);
    }

    /** POST /center/students/{id}/documents/{typeId} (multipart files[]) : uploaded at the counter for a student. */
    public function upload_for($studentId, $typeId)
    {
        $student = $this->center_student((int) $studentId);
        $this->store($student, (int) $typeId);
        $this->json(array_merge(['message' => 'Uploaded.'], $this->checklist_payload($student)), 201);
    }

    /** DELETE /student-document-files/{id} : withdraw a copy that is not yet under review. */
    public function remove_file($fileId)
    {
        $file = $this->accessible_file((int) $fileId);
        $student = $this->student_for_file($file);
        try {
            $this->StudentDocumentModel->remove_copy($file, $this->user_id());
        } catch (DomainException $e) {
            $this->json_error($e->getMessage(), 422);
        }
        $this->json(array_merge(['message' => 'Copy removed.'], $this->checklist_payload($student)));
    }

    /** GET /student-document-files/{id}?download=1 */
    public function file($fileId)
    {
        $file = $this->accessible_file((int) $fileId);
        // Staff access to personal documents is audited.
        if ($this->is_center_admin()) {
            $this->AuditModel->log($this->user_id(), $this->center_id(), isset($_GET['download']) ? 'downloaded' : 'viewed',
                'student_document', (int) $file['student_document_id'], "Opened {$file['original_name']} (student #{$file['student_id']})");
        }
        FileStore::send('student_documents', $file['storage_key'], $file['original_name'], $file['mime_type'], isset($_GET['download']));
    }

    /** POST /student-documents/{id}/review  { action: under_review|verified|rejected, remarks } */
    public function review($docId)
    {
        if (!$this->is_center_admin()) {
            $this->json_error('Only the learning center verifies documents.', 403);
        }
        $doc = $this->StudentDocumentModel->document((int) $docId, $this->center_id());
        if (!$doc) {
            $this->json_error('Document not found.', 404);
        }
        $input = $this->input();
        $action = $this->str($input, 'action');
        $remarks = mb_substr($this->str($input, 'remarks'), 0, 500);
        try {
            $this->StudentDocumentModel->review($doc, $action, $remarks, $this->user_id());
        } catch (DomainException $e) {
            $this->json_error($e->getMessage(), 422);
        }
        $student = $this->center_student((int) $doc['student_id']);
        $labels = ['under_review' => 'put under review', 'verified' => 'verified', 'rejected' => 'rejected'];
        $this->AuditModel->log($this->user_id(), $this->center_id(), $action, 'student_document', (int) $doc['id'],
            "{$doc['type_name']} of {$student['full_name']} {$labels[$action]}" . ($remarks !== '' ? ": {$remarks}" : ''));
        if ($action !== 'under_review') {
            $this->db->table('notifications')->insert([
                'user_id' => (int) $doc['student_id'],
                'title' => $action === 'verified' ? "{$doc['type_name']} verified" : "{$doc['type_name']} needs a new copy",
                'message' => mb_substr($action === 'verified' ? 'Your document was verified by the learning center.' : ($remarks ?: 'Please upload a new copy.'), 0, 500),
                'entity_type' => 'student_document',
                'entity_id' => (int) $doc['id'],
            ]);
        }
        $this->json(array_merge(['message' => "Document {$labels[$action]}."], $this->checklist_payload($student)));
    }

    /* ---------------------------------------------------------------- */

    private function store(array $student, $typeId)
    {
        $type = $this->StudentDocumentModel->type($typeId);
        if (!$type) {
            $this->json_error('That requirement does not exist.', 404);
        }
        if (!StudentDocumentModel::applies($type, $student['civil_status'])) {
            $this->json_error("{$type['name']} is only needed when the civil status is " . $type['condition_code'] . '.', 422);
        }
        $uploads = array_merge(FileStore::uploads('files'), FileStore::uploads('file'));
        $uploads = array_values(array_filter($uploads, fn ($u) => (int) ($u['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if (!$uploads) {
            $this->json_error('Choose at least one file.', 422);
        }
        foreach ($uploads as $upload) {
            $problem = FileStore::problem('student_documents', $upload);
            if ($problem) {
                $this->json_error($problem, 422);
            }
        }
        try {
            $docId = $this->StudentDocumentModel->add_copies($student['training_center_id'], $student['id'], $type, $uploads, $this->user_id());
        } catch (DomainException $e) {
            $this->json_error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $this->json_error('The file could not be saved. Try again.', 500);
        }
        $this->AuditModel->log($this->user_id(), $student['training_center_id'], 'uploaded', 'student_document', $docId,
            "Uploaded " . count($uploads) . " cop" . (count($uploads) === 1 ? 'y' : 'ies') . " of {$type['name']} for {$student['full_name']}");
    }

    private function checklist_payload(array $student)
    {
        $list = $this->StudentDocumentModel->checklist($student['training_center_id'], $student['id'], $student['civil_status']);
        return [
            'student' => [
                'id' => (int) $student['id'],
                'full_name' => $student['full_name'],
                'student_number' => $student['student_number'],
                'civil_status' => $student['civil_status'],
            ],
            'documents' => $list['items'],
            'summary' => $list['summary'],
            'history' => $this->StudentDocumentModel->student_history($student['training_center_id'], $student['id'], 50),
        ];
    }

    private function self_student()
    {
        if ($this->role() !== 'student') {
            $this->json_error('This page is for students.', 403);
        }
        $student = $this->StudentRecordModel->own($this->user_id());
        if (!$student || !$student['training_center_id']) {
            $this->json_error('Your account is not linked to a learning center yet.', 403);
        }
        return $student;
    }

    private function center_student($studentId)
    {
        if (!$this->is_center_admin()) {
            $this->json_error('Only the learning center can manage student records.', 403);
        }
        $student = $this->StudentRecordModel->find_in_center($studentId, $this->center_id());
        if (!$student) {
            $this->json_error('Student not found.', 404);
        }
        return $student;
    }

    /** The file if the user may open it: the owning student, or an admin of the document's center. */
    private function accessible_file($fileId)
    {
        $file = $this->StudentDocumentModel->file($fileId);
        $allowed = $file && (
            ($this->role() === 'student' && (int) $file['student_id'] === $this->user_id())
            || ($this->is_center_admin() && (int) $file['training_center_id'] === $this->center_id())
        );
        if (!$allowed) {
            $this->json_error('File not found.', 404);
        }
        return $file;
    }

    private function student_for_file(array $file)
    {
        return $this->role() === 'student' ? $this->self_student() : $this->center_student((int) $file['student_id']);
    }
}
