<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class DocumentAIController extends MY_Controller
{
    private $documentAI;

    public function __construct()
    {
        parent::__construct();
        $this->require_role('admin', 'Administrator access is required.');
        $this->documentAI = $this->call->library('DocumentAIService');
    }

    public function analyze()
    {
        $upload = $_FILES['document_file'] ?? null;
        $validationError = $this->validate_upload($upload);
        if ($validationError !== null) {
            $this->json_error($validationError, 422);
        }
        $this->run(basename((string) $upload['name']), function () use ($upload) {
            return $this->documentAI->analyze_image($upload['tmp_name']);
        });
    }

    public function sample()
    {
        $samplePath = DocumentAIService::ai_dir() . 'samples' . DIRECTORY_SEPARATOR . 'invoice.png';
        if (!is_file($samplePath)) {
            $this->json_error('The bundled sample invoice is missing.', 500);
        }
        $this->run('Bundled RVL-CDIP invoice sample', function () use ($samplePath) {
            return $this->documentAI->analyze_image($samplePath);
        });
    }

    public function classify_text()
    {
        $text = $this->str($this->input(), 'ocr_text');
        $this->run('', function () use ($text) {
            return $this->documentAI->classify_text($text);
        }, $text);
    }

    private function run($sourceName, $analysis, $ocrText = '')
    {
        try {
            $result = $analysis();
        } catch (RuntimeException $error) {
            error_log('Document AI analysis failed: ' . $error->getMessage());
            $this->json_error($error->getMessage(), 500, ['ocr_text' => $ocrText]);
        }
        $this->json(['result' => $result, 'source_name' => $sourceName, 'ocr_text' => $ocrText]);
    }

    private function validate_upload($upload)
    {
        if (!is_array($upload) || !isset($upload['error']) || is_array($upload['error'])) {
            return 'Choose a document image to analyze.';
        }
        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            return (int) $upload['error'] === UPLOAD_ERR_NO_FILE
                ? 'Choose a document image to analyze.'
                : 'The upload did not complete. Try a smaller image.';
        }
        if (!is_uploaded_file($upload['tmp_name']) || (int) $upload['size'] < 1) {
            return 'The uploaded file could not be verified.';
        }
        if ((int) $upload['size'] > 10 * 1024 * 1024) {
            return 'Choose an image smaller than 10 MB.';
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($upload['tmp_name']);
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/bmp', 'image/tiff'];
        if (!in_array($mimeType, $allowedTypes, true)) {
            return 'Use a JPEG, PNG, WebP, BMP, or TIFF image.';
        }
        $dimensions = getimagesize($upload['tmp_name']);
        if ($dimensions === false || $dimensions[0] * $dimensions[1] > 40_000_000) {
            return 'The image is invalid or exceeds the 40-megapixel limit.';
        }
        return null;
    }
}
