<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** document_archive: main office records, with the OCR text and AI labels from upload. */
class DocumentArchiveModel extends Model
{
    protected $table = 'document_archive';
    protected $primary_key = 'id';
    protected $fillable = [
        'training_center_id', 'uploaded_by', 'storage_key', 'original_name', 'mime_type', 'file_size',
        'file_hash', 'pages_analyzed', 'text_source', 'ocr_text', 'ocr_error', 'ocr_word_count',
        'image_label', 'image_confidence', 'text_label', 'category', 'status', 'access_level', 'expires_at',
    ];

    /** Document columns plus the uploader's name. */
    private function with_uploader()
    {
        return $this->db->table('document_archive d')
            ->select('d.id, d.original_name, d.mime_type, d.file_size, d.ocr_text, d.ocr_error,
                      d.image_label, d.text_label, d.created_at, u.full_name AS uploader_name')
            ->left_join('users u', 'u.id = d.uploaded_by');
    }

    public function recent($centerId, $limit = 25)
    {
        return $this->with_uploader()
            ->where('d.training_center_id', (int) $centerId)
            ->order_by('d.created_at', 'DESC')
            ->limit((int) $limit)
            ->get_all();
    }

    /** Documents whose name, OCR text, AI labels, or uploader match any of the terms. */
    public function search($centerId, array $terms, $limit = 25)
    {
        $fields = ['d.original_name', 'd.ocr_text', 'd.image_label', 'd.text_label', 'u.full_name', 'u.username'];
        return $this->with_uploader()
            ->where('d.training_center_id', (int) $centerId)
            ->grouped(function ($q) use ($terms, $fields) {
                $first = true;
                foreach ($terms as $term) {
                    $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
                    foreach ($fields as $field) {
                        $first ? $q->like($field, $pattern) : $q->or_like($field, $pattern);
                        $first = false;
                    }
                }
            })
            ->order_by('d.created_at', 'DESC')
            ->limit((int) $limit)
            ->get_all();
    }

    /** The document, if it belongs to the center. */
    public function in_center($id, $centerId)
    {
        return $this->db->table($this->table)
            ->select('id, storage_key, original_name, mime_type, training_center_id')
            ->where(['id' => (int) $id, 'training_center_id' => (int) $centerId])
            ->get();
    }

    /** The center's only document, or null when it has none or several. */
    public function single_document($centerId)
    {
        $documents = $this->db->table($this->table)
            ->select('id, original_name')
            ->where('training_center_id', (int) $centerId)
            ->order_by('created_at', 'DESC')
            ->limit(2)
            ->get_all();
        return count($documents) === 1 ? $documents[0] : null;
    }

    public function count_for_center($centerId)
    {
        return (int) $this->db->table($this->table)->where('training_center_id', (int) $centerId)->count();
    }
}
