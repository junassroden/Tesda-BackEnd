<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class DocumentArchiveModel extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function central_office()
    {
        return $this->db->raw(
            'SELECT id FROM training_centers
             WHERE center_code = ? AND is_active = 1
             LIMIT 1',
            ['TESDA-MAIN']
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function create($document)
    {
        $this->db->raw(
            'INSERT INTO document_archive
                (training_center_id, uploaded_by, storage_key, original_name,
                 mime_type, file_size, ocr_text, ocr_error, image_label, text_label)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $document['training_center_id'],
                $document['uploaded_by'],
                $document['storage_key'],
                $document['original_name'],
                $document['mime_type'],
                $document['file_size'],
                $document['ocr_text'],
                $document['ocr_error'],
                $document['image_label'],
                $document['text_label']
            ]
        );
    }

    public function recent($centerId, $limit = 25)
    {
        return $this->db->raw(
            'SELECT d.id, d.original_name, d.mime_type, d.file_size, d.ocr_text,
                    d.ocr_error, d.image_label, d.text_label, d.created_at,
                    u.full_name AS uploader_name
             FROM document_archive d
             LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.training_center_id = ?
             ORDER BY d.created_at DESC, d.id DESC
             LIMIT ' . (int) $limit,
            [(int) $centerId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function search($centerId, $terms, $limit = 25)
    {
        $conditions = [];
        $arguments = [(int) $centerId];
        foreach ($terms as $term) {
            $conditions[] = '(d.original_name LIKE ? OR d.ocr_text LIKE ?
                OR d.image_label LIKE ? OR d.text_label LIKE ?
                OR u.full_name LIKE ? OR u.username LIKE ?)';
            $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            array_push($arguments, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern);
        }

        return $this->db->raw(
            'SELECT d.id, d.original_name, d.mime_type, d.file_size, d.ocr_text,
                    d.ocr_error, d.image_label, d.text_label, d.created_at,
                    u.full_name AS uploader_name
             FROM document_archive d
             LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.training_center_id = ? AND (' . implode(' OR ', $conditions) . ')
             ORDER BY d.created_at DESC, d.id DESC
             LIMIT ' . (int) $limit,
            $arguments
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find($id, $centerId)
    {
        return $this->db->raw(
            'SELECT id, storage_key, original_name, mime_type, training_center_id
             FROM document_archive
             WHERE id = ? AND training_center_id = ?
             LIMIT 1',
            [(int) $id, (int) $centerId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function single_document($centerId)
    {
        $documents = $this->db->raw(
            'SELECT id, original_name
             FROM document_archive
             WHERE training_center_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 2',
            [(int) $centerId]
        )->fetchAll(PDO::FETCH_ASSOC);

        return count($documents) === 1 ? $documents[0] : null;
    }

    public function count($centerId)
    {
        $result = $this->db->raw(
            'SELECT COUNT(*) AS total FROM document_archive WHERE training_center_id = ?',
            [(int) $centerId]
        )->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['total'] ?? 0);
    }
}
