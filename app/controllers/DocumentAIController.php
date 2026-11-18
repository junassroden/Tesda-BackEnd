<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/DocumentAIService.php';

class DocumentAIController extends Controller
{
    private $documentAI;

    public function __construct()
    {
        parent::__construct();
        $this->documentAI = new DocumentAIService();
    }

    public function index()
    {
        $data = $this->page_data();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $upload = $_FILES['document_file'] ?? null;
            $validationError = $this->validate_upload($upload);
            if ($validationError !== null) {
                $data['error'] = $validationError;
            } else {
                $data['source_name'] = basename((string) $upload['name']);
                try {
                    $data['result'] = $this->documentAI->analyze_image($upload['tmp_name']);
                } catch (RuntimeException $error) {
                    $data['error'] = $error->getMessage();
                    error_log('Document AI image analysis failed: ' . $error->getMessage());
                }
            }
        }
        $this->render($data);
    }

    public function sample()
    {
        $data = $this->page_data();
        $samplePath = ROOT_DIR . 'ai' . DIRECTORY_SEPARATOR . 'samples'
            . DIRECTORY_SEPARATOR . 'invoice.png';
        if (!is_file($samplePath)) {
            $data['error'] = 'The bundled sample invoice is missing.';
        } else {
            $data['source_name'] = 'Bundled RVL-CDIP invoice sample';
            try {
                $data['result'] = $this->documentAI->analyze_image($samplePath);
            } catch (RuntimeException $error) {
                $data['error'] = $error->getMessage();
                error_log('Document AI sample analysis failed: ' . $error->getMessage());
            }
        }
        $this->render($data);
    }

    public function classify_text()
    {
        $data = $this->page_data();
        $text = trim((string) ($_POST['ocr_text'] ?? ''));
        $data['ocr_text'] = $text;
        try {
            $data['result'] = $this->documentAI->classify_text($text);
        } catch (RuntimeException $error) {
            $data['error'] = $error->getMessage();
            error_log('Document AI text classification failed: ' . $error->getMessage());
        }
        $this->render($data);
    }

    private function page_data()
    {
        return [
            'result' => null,
            'error' => '',
            'source_name' => '',
            'ocr_text' => '',
            'active_page' => 'document-ai'
        ];
    }

    private function render($data)
    {
        $this->call->view('document_ai/index', $data);
    }

    private function validate_upload($upload)
    {
        if (!is_array($upload) || !isset($upload['error'])) {
            return 'Choose a document image to analyze.';
        }
        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            return $upload['error'] === UPLOAD_ERR_NO_FILE
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
