<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class DocumentArchiveController extends MY_Controller
{
    private $documentAI;
    private $archiveDirectory;

    public function __construct()
    {
        parent::__construct();
        $this->require_archive_access();
        $this->call->model('DocumentArchiveModel');
        $this->call->model('AuditModel');
        $this->call->model('TrainingCenterModel');
        $this->documentAI = $this->call->library('DocumentAIService');
        $this->archiveDirectory = config_item('storage_dir') . 'document-archive';
    }

    public function index()
    {
        $centerId = $this->archive_center_id();
        $this->call->model('LedgerModel');
        $documents = $this->DocumentArchiveModel->recent($centerId);
        foreach ($documents as &$document) {
            $document['ledger'] = $this->LedgerModel->entity_status('document', (int) $document['id']);
        }
        unset($document);
        $this->json([
            'documents' => $documents,
            'total_documents' => $this->DocumentArchiveModel->count_for_center($centerId)
        ]);
    }

    public function upload()
    {
        $centerId = $this->archive_center_id();
        $upload = $this->file('document_file');
        $validationError = $this->validate_upload($upload);
        if ($validationError !== null) {
            $this->json_error($validationError, 422);
        }
        $this->process_upload($upload, $centerId);
    }

    public function chat_history()
    {
        $this->json(['messages' => $this->chat_messages()]);
    }

    public function chat()
    {
        $centerId = $this->archive_center_id();
        $input = $this->input();
        $question = $this->str($input, 'question');
        if ($question === '' || mb_strlen($question, 'UTF-8') > 240) {
            $this->json_error(
                $question === '' ? 'Enter a message so I know what to look for.' : 'Keep your message under 240 characters.',
                422
            );
        }

        $messages = $this->chat_messages();
        $messages[] = ['role' => 'user', 'text' => $question, 'documents' => []];
        $response = $this->respond_to_chat($question, $centerId);
        $messages[] = [
            'role' => 'assistant',
            'text' => $response['text'],
            'documents' => $response['documents'],
            'terms' => isset($response['terms']) ? $response['terms'] : []
        ];
        $messages = array_slice($messages, -20);
        $this->session->set_userdata('document_chat_history', $messages);
        $this->json(['messages' => $messages]);
    }

    public function clear_chat()
    {
        $this->session->unset_userdata(['document_chat_history', 'document_chat_document_ids']);
        $this->json(['messages' => [], 'message' => 'Chat history cleared.']);
    }

    private function chat_messages()
    {
        $messages = $this->session->userdata('document_chat_history');
        return is_array($messages) ? array_values($messages) : [];
    }

    private function archive_center_id()
    {
        $center = $this->TrainingCenterModel->main_office();
        if (!$center) {
            $this->json_error('The TESDA main office record is missing or inactive. Run the document archive SQL setup.', 500);
        }
        return (int) $center['id'];
    }

    public function download($id)
    {
        $this->send_file((int) $id, false);
    }

    /** GET /documents/{id}/view: the file shown in the browser (PDF viewer or image). */
    public function view($id)
    {
        $this->send_file((int) $id, true);
    }

    private function send_file($id, $inline)
    {
        $document = $this->DocumentArchiveModel->in_center((int) $id, $this->archive_center_id());
        if (!$document) {
            $this->json_error('Document not found.', 404);
        }

        $path = $this->archiveDirectory . DIRECTORY_SEPARATOR . $document['storage_key'];
        $realDirectory = realpath($this->archiveDirectory);
        $realPath = realpath($path);
        if (
            $realDirectory === false
            || $realPath === false
            || strpos($realPath, $realDirectory . DIRECTORY_SEPARATOR) !== 0
            || !is_file($realPath)
        ) {
            error_log('Document archive file is missing or outside its storage directory: ' . (int) $id);
            $this->json_error('Document not found.', 404);
        }

        $file = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $file->file($realPath) ?: 'application/octet-stream';
        // Only types the browser renders itself are shown inline; anything else downloads.
        $viewable = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/bmp'];
        $disposition = $inline && in_array($mimeType, $viewable, true) ? 'inline' : 'attachment';
        $downloadName = preg_replace('/[\r\n"\\\\]/', '_', basename($document['original_name']));
        $this->io->set_status_code(200);
        $this->io->add_header([
            'Content-Type' => $mimeType,
            'Content-Length' => (string) filesize($realPath),
            'Content-Disposition' => $disposition . '; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($document['original_name']),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], null);
        $this->io->send(); // headers only; the file is streamed below
        readfile($realPath);
        exit;
    }

    /** PUT /documents/{id}: renames the document. The file extension always stays the same. */
    public function rename($id)
    {
        $document = $this->DocumentArchiveModel->in_center((int) $id, $this->archive_center_id());
        if (!$document) {
            $this->json_error('Document not found.', 404);
        }

        // Characters that are not allowed in Windows file names, and control characters.
        $name = preg_replace('#[\x00-\x1F\x7F\\\\/:*?"<>|]+#u', ' ', $this->str($this->input(), 'name'));
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name), " .");
        $extension = pathinfo($document['original_name'], PATHINFO_EXTENSION);
        if ($extension !== '' && preg_match('/\.' . preg_quote($extension, '/') . '$/i', $name)) {
            $name = trim(substr($name, 0, -strlen($extension) - 1), " .");
        }
        if ($name === '') {
            $this->json_error('Enter a name for the document.', 422);
        }
        $fullName = mb_substr($name, 0, 240, 'UTF-8') . ($extension !== '' ? '.' . $extension : '');

        $this->DocumentArchiveModel->update((int) $document['id'], ['original_name' => $fullName]);
        $this->json(['message' => 'Document renamed.', 'original_name' => $fullName]);
    }

    /** DELETE /documents/{id}: removes the archive record and its stored file. */
    public function delete($id)
    {
        $document = $this->DocumentArchiveModel->in_center((int) $id, $this->archive_center_id());
        if (!$document) {
            $this->json_error('Document not found.', 404);
        }

        try {
            // The deletion is recorded on the blockchain ledger, so the archive block of
            // this document reads "removed" instead of "missing".
            $this->db->transaction();
            $this->AuditModel->record('document', (int) $document['id'], 'deleted', $this->user_id(), [
                'document_id' => (int) $document['id'],
                'sha256' => $document['file_hash'] ?? null,
                'deleted_at' => date('Y-m-d H:i:s'),
            ]);
            $this->DocumentArchiveModel->delete((int) $document['id']);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('Document archive delete failed: ' . $error->getMessage());
            $this->json_error('The document could not be deleted.', 500);
        }

        $path = $this->archiveDirectory . DIRECTORY_SEPARATOR . basename($document['storage_key']);
        if (is_file($path) && !unlink($path)) {
            error_log('Could not remove a deleted document archive file: ' . $document['storage_key']);
        }

        $remembered = $this->session->userdata('document_chat_document_ids');
        if (is_array($remembered)) {
            $this->session->set_userdata(
                'document_chat_document_ids',
                array_values(array_filter($remembered, fn ($rememberedId) => (int) $rememberedId !== (int) $document['id']))
            );
        }

        $this->json(['message' => 'Document deleted.']);
    }

    private function process_upload($upload, $centerId)
    {
        try {
            if ($upload['mime_type'] === 'application/pdf') {
                set_time_limit(0);
                $analysis = $this->documentAI->analyze_pdf($upload['tmp_name']);
            } else {
                $analysis = $this->documentAI->analyze_image($upload['tmp_name']);
            }
            try {
                $storageKey = bin2hex(random_bytes(16)) . $this->extension_for_mime($upload['mime_type']);
            } catch (Exception $error) {
                throw new RuntimeException('A secure archive filename could not be generated.', 0, $error);
            }
            if (!is_dir($this->archiveDirectory)
                && !mkdir($this->archiveDirectory, 0700, true)
                && !is_dir($this->archiveDirectory)) {
                throw new RuntimeException('The private document storage directory could not be created.');
            }

            $destination = $this->archiveDirectory . DIRECTORY_SEPARATOR . $storageKey;
            if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                throw new RuntimeException('The uploaded file could not be moved into private storage.');
            }

            try {
                $this->db->transaction();
                $documentId = (int) $this->DocumentArchiveModel->insert([
                    'training_center_id' => $centerId,
                    'uploaded_by' => $this->user_id(),
                    'storage_key' => $storageKey,
                    'original_name' => $this->safe_original_name($upload['name']),
                    'mime_type' => $upload['mime_type'],
                    'file_size' => (int) $upload['size'],
                    'file_hash' => hash_file('sha256', $destination),
                    'ocr_text' => $analysis['ocr']['text'] ?? '',
                    'ocr_error' => $analysis['ocr']['error'] ?? null,
                    'image_label' => $analysis['image_classification']['label'] ?? null,
                    'text_label' => $analysis['text_classification']['label'] ?? null
                ]);
                // The stored file's SHA-256 fingerprint goes on the blockchain ledger.
                $this->AuditModel->record('document', $documentId, 'archived', $this->user_id());
                $this->db->commit();
            } catch (Throwable $error) {
                $this->db->roll_back();
                if (is_file($destination) && !unlink($destination)) {
                    error_log('Could not remove an unindexed document archive file: ' . $storageKey);
                }
                throw new RuntimeException('The document was analyzed but could not be added to the archive.', 0, $error);
            }

            $pagesAnalyzed = (int) ($analysis['pages_analyzed'] ?? 1);
            $message = 'Document saved. OCR processed ' . $pagesAnalyzed
                . ($pagesAnalyzed === 1 ? ' page' : ' pages')
                . ' and extracted ' . str_word_count($analysis['ocr']['text'] ?? '') . ' words.';
        } catch (RuntimeException $error) {
            error_log('Document archive upload failed: ' . $error->getMessage());
            $this->json_error($error->getMessage(), 500);
        }
        $this->json(['message' => $message], 201);
    }

    private function respond_to_chat($question, $centerId)
    {
        $normalizedQuestion = strtolower(trim($question));
        if (preg_match('/\b(?:is this|is it)\s+working\b|\bare you (?:there|working|online)\b|\bcan you hear me\b/i', $question)) {
            return [
                'text' => 'Yes, I am here and working. I can search the archive by filename or words printed on a document.',
                'documents' => []
            ];
        }
        if (preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening)(?:\s+there)?[.!?,\s]*$/', $normalizedQuestion)) {
            return [
                'text' => 'Hello. Tell me a filename or a few words from the document, and I will search the archive.',
                'documents' => []
            ];
        }
        if (preg_match('/^(?:can you help(?: me)?|what can you do)[.!?\s]*$/i', trim($question))) {
            return [
                'text' => 'I can find archived documents using their filename or words extracted by OCR. Include a name or a few words to search.',
                'documents' => []
            ];
        }
        if (preg_match('/\b(?:what did i ask|what was my question|repeat my last question)\b/i', $question)) {
            $lastQuestion = $this->last_user_question();
            return [
                'text' => $lastQuestion !== ''
                    ? 'You asked: "' . $lastQuestion . '"'
                    : 'We have not exchanged a question yet. Tell me what document you are looking for.',
                'documents' => []
            ];
        }
        if (preg_match('/^(thanks|thank you|thanks a lot|i appreciate it)[.!?\s]*$/', $normalizedQuestion)) {
            return [
                'text' => 'You are welcome. Let me know if you need help finding another document.',
                'documents' => []
            ];
        }

        $searchQuestion = preg_replace('/^(?:hi|hello|hey)(?:\s+there)?[\s,!.?]*/i', '', $question);
        $terms = $this->search_terms($searchQuestion);
        $isDocumentRequest = preg_match('/\b(get|download|open|send|give|retrieve|access|have|view|share)\b/i', $question);
        if (!$terms && $isDocumentRequest) {
            $documents = $this->remembered_chat_documents($centerId);
            if (!$documents) {
                $documents = $this->single_archive_document($centerId);
            }
            return $documents
                ? ['text' => 'Of course. Here is the document from the archive:', 'documents' => [$documents[0]]]
                : ['text' => 'Of course. Tell me the filename or a few words from the document, and I will look it up.', 'documents' => []];
        }

        if (
            !$terms
            && preg_match('/\b(that|this|it|same|last|previous|one)\b/i', $question)
        ) {
            $documents = $this->remembered_chat_documents($centerId);
            if (!$documents) {
                $documents = $this->single_archive_document($centerId);
            }
            if ($documents) {
                return [
                    'text' => 'We were looking at this document:',
                    'documents' => [$documents[0]]
                ];
            }
        }

        if (!$terms) {
            $documents = $this->remembered_chat_documents($centerId);
            if (!$documents) {
                $documents = $this->single_archive_document($centerId);
            }
            if (
                $documents
                && preg_match('/\b(where|which|what|uploaded|upload|document|file)\b/i', $question)
            ) {
                return [
                    'text' => 'There is one document in the archive. This may be the one you mean:',
                    'documents' => [$documents[0]]
                ];
            }
            return [
                'text' => 'Of course. Share a filename or a few words printed on the document, and I will search for it.',
                'documents' => []
            ];
        }

        // Search with word stems, so "certificates" also finds "certificate".
        $stems = array_values(array_unique(array_map([$this, 'stem'], $terms)));
        $matches = $this->DocumentArchiveModel->search($centerId, $stems, 100);
        if (!$matches) {
            $documents = $this->single_archive_document($centerId);
            if ($documents) {
                return [
                    'text' => 'I could not match those words exactly, but there is one document in the archive. Could this be the one?',
                    'documents' => $documents,
                    'terms' => $terms
                ];
            }
            return [
                'text' => 'No document mentions ' . $this->quote_terms($terms) . '. Try another word from the filename or from the text printed on the document.',
                'documents' => [],
                'terms' => $terms
            ];
        }

        $ranked = $this->rank_matches($matches, $terms);
        $documents = array_slice($ranked, 0, 5);
        $this->remember_documents_in_chat($documents);

        $total = count($ranked);
        $best = count($documents[0]['matched']);
        $wordCount = count($terms);
        if ($wordCount === 1) {
            $text = 'I found ' . $total . ' document' . ($total === 1 ? '' : 's') . ' that mention ' . $this->quote_terms($terms) . '.';
        } elseif ($best === $wordCount) {
            $complete = count(array_filter($ranked, function ($d) use ($wordCount) { return count($d['matched']) === $wordCount; }));
            $text = $complete . ' document' . ($complete === 1 ? ' mentions' : 's mention') . ' all ' . $wordCount . ' of your words'
                . ($total > $complete ? ', and ' . ($total - $complete) . ' more mention some of them.' : '.');
        } else {
            $text = 'No document mentions all ' . $wordCount . ' words. These match the most (' . $best . ' of ' . $wordCount . '):';
        }
        if ($total > count($documents)) {
            $text .= ' Here are the ' . count($documents) . ' closest.';
        }

        return ['text' => $text, 'documents' => $documents, 'terms' => $terms];
    }

    /**
     * Scores each document word by word: a word in the file name counts most, then the
     * AI document type, then the OCR text, then the uploader. Documents that match more of
     * the words come first; ties go to the higher score, then to the newer upload.
     */
    private function rank_matches(array $matches, array $terms)
    {
        $weights = ['name' => 5, 'label' => 3, 'text' => 2, 'uploader' => 1];
        $ranked = [];
        foreach ($matches as $row) {
            $fields = [
                'name' => mb_strtolower((string) $row['original_name'], 'UTF-8'),
                'label' => mb_strtolower(trim($row['text_label'] . ' ' . $row['image_label']), 'UTF-8'),
                'text' => mb_strtolower((string) $row['ocr_text'], 'UTF-8'),
                'uploader' => mb_strtolower((string) $row['uploader_name'], 'UTF-8'),
            ];
            $matched = [];
            $score = 0;
            foreach ($terms as $term) {
                $stem = $this->stem($term);
                $hit = false;
                foreach ($weights as $field => $weight) {
                    if ($fields[$field] !== '' && mb_strpos($fields[$field], $stem, 0, 'UTF-8') !== false) {
                        $score += $weight;
                        $hit = true;
                    }
                }
                if ($hit) {
                    $matched[] = $term;
                }
            }
            if (!$matched) {
                continue; // matched only through a field this scoring does not read
            }
            $ranked[] = [
                'id' => (int) $row['id'],
                'original_name' => (string) $row['original_name'],
                'mime_type' => (string) $row['mime_type'],
                'label' => (string) ($row['text_label'] ?: $row['image_label']),
                'created_at' => (string) $row['created_at'],
                'matched' => $matched,
                'snippet' => $this->snippet((string) $row['ocr_text'], $matched),
                '_score' => $score,
            ];
        }

        usort($ranked, function ($a, $b) {
            return [count($b['matched']), $b['_score'], $b['created_at']] <=> [count($a['matched']), $a['_score'], $a['created_at']];
        });
        foreach ($ranked as &$document) {
            unset($document['_score']);
        }
        unset($document);
        return $ranked;
    }

    /** About 160 characters of OCR text around the first matched word, or '' when the text has none. */
    private function snippet($text, array $matched)
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        $position = false;
        foreach ($matched as $term) {
            $found = mb_stripos($text, $this->stem($term), 0, 'UTF-8');
            if ($found !== false && ($position === false || $found < $position)) {
                $position = $found;
            }
        }
        if ($position === false) {
            return '';
        }
        $start = max(0, $position - 60);
        $excerpt = mb_substr($text, $start, 160, 'UTF-8');
        return ($start > 0 ? '…' : '') . $excerpt . ($start + 160 < mb_strlen($text, 'UTF-8') ? '…' : '');
    }

    /** A light plural stem: "certificates" -> "certificat", "batches" -> "batch", "copies" -> "cop". */
    private function stem($word)
    {
        $length = strlen($word);
        if ($length > 4 && substr($word, -3) === 'ies') {
            return substr($word, 0, -3);
        }
        if ($length > 4 && preg_match('/(?:ches|shes|xes|sses)$/', $word)) {
            return substr($word, 0, -2);
        }
        if ($length > 4 && substr($word, -1) === 's' && substr($word, -2) !== 'ss') {
            return substr($word, 0, -1);
        }
        return $word;
    }

    private function quote_terms(array $terms)
    {
        $quoted = array_map(function ($t) { return '"' . $t . '"'; }, $terms);
        if (count($quoted) === 1) {
            return $quoted[0];
        }
        $last = array_pop($quoted);
        return implode(', ', $quoted) . ' or ' . $last;
    }

    private function single_archive_document($centerId)
    {
        $document = $this->DocumentArchiveModel->single_document((int) $centerId);
        if (!$document) {
            return [];
        }

        $summary = [
            'id' => (int) $document['id'],
            'original_name' => (string) $document['original_name']
        ];
        $this->remember_documents_in_chat([$summary]);
        return [$summary];
    }

    private function remember_documents_in_chat($documents)
    {
        $this->session->set_userdata(
            'document_chat_document_ids',
            array_column($documents, 'id')
        );
    }

    private function search_terms($question)
    {
        $stopWords = [
            'a', 'about', 'after', 'ago', 'and', 'are', 'can', 'could', 'day',
            'days', 'did', 'document', 'documents', 'download', 'file', 'files',
            'find', 'for', 'from', 'get', 'give', 'have', 'help', 'i', 'in', 'is',
            'it', 'last', 'link', 'links', 'me', 'month', 'my', 'of', 'on', 'open',
            'please', 'retrieve', 'same', 'send', 'share', 'show', 'tell', 'the',
            'this', 'to', 'today', 'upload', 'uploaded', 'view', 'was', 'were',
            'week', 'where', 'which', 'with', 'you', 'your', 'yesterday', 'access',
            'few', 'who', 'one', 'that', 'what', 'any', 'all', 'some', 'there', 'need',
            'want', 'looking', 'search', 'an', 'or', 'by', 'at', 'be', 'do', 'does', 'has', 'had',
            'hi', 'hello', 'hey', 'ok', 'okay', 'thanks', 'thank',
            // Filipino / Taglish filler, e.g. "pakihanap po yung certificate"
            'ang', 'ng', 'sa', 'mga', 'yung', 'iyong', 'ung', 'po', 'opo', 'naman', 'paki', 'pakihanap',
            'pakibigay', 'pakisend', 'hanapin', 'hanap', 'ko', 'ako', 'akin', 'na', 'nga', 'lang',
            'para', 'ba', 'yun', 'iyon', 'ito', 'dito', 'nasaan', 'saan', 'meron', 'may', 'pa',
            'dokumento', 'kailangan', 'gusto', 'si', 'ni', 'kay'
        ];
        // Split the question into words and keep every meaningful one, in order, once.
        $words = preg_split('/[^\p{L}\p{N}._-]+/u', mb_strtolower($question, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        $terms = [];
        foreach ($words as $word) {
            $word = trim($word, '._-');
            if (mb_strlen($word, 'UTF-8') > 1 && !in_array($word, $stopWords, true) && !in_array($word, $terms, true)) {
                $terms[] = $word;
            }
            if (count($terms) >= 8) {
                break;
            }
        }
        return $terms;
    }

    private function last_user_question()
    {
        $messages = $this->session->userdata('document_chat_history');
        if (!is_array($messages)) {
            return '';
        }
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (
                isset($messages[$index]['role'], $messages[$index]['text'])
                && $messages[$index]['role'] === 'user'
                && is_string($messages[$index]['text'])
            ) {
                return $messages[$index]['text'];
            }
        }
        return '';
    }

    private function remembered_chat_documents($centerId)
    {
        $documentIds = $this->session->userdata('document_chat_document_ids');
        if (!is_array($documentIds)) {
            return [];
        }

        $documents = [];
        foreach (array_slice($documentIds, 0, 3) as $documentId) {
            if (!is_numeric($documentId) || (int) $documentId < 1) {
                continue;
            }
            $document = $this->DocumentArchiveModel->in_center((int) $documentId, (int) $centerId);
            if ($document) {
                $documents[] = [
                    'id' => (int) $document['id'],
                    'original_name' => (string) $document['original_name']
                ];
            }
        }
        return $documents;
    }

    private function validate_upload(&$upload)
    {
        if (
            !is_array($upload)
            || !isset($upload['error'])
            || is_array($upload['error'])
            || !is_numeric($upload['error'])
            || !isset($upload['tmp_name'])
            || !is_string($upload['tmp_name'])
            || !isset($upload['size'])
            || !is_numeric($upload['size'])
            || !isset($upload['name'])
            || !is_string($upload['name'])
        ) {
            return 'Choose a document image or PDF to archive.';
        }
        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            return $upload['error'] === UPLOAD_ERR_NO_FILE
                ? 'Choose a document image or PDF to archive.'
                : 'The upload did not complete. Try a file smaller than 10 MB.';
        }
        if (!is_uploaded_file($upload['tmp_name']) || (int) $upload['size'] < 1) {
            return 'The uploaded file could not be verified.';
        }
        if ((int) $upload['size'] > 10 * 1024 * 1024) {
            return 'Choose a file smaller than 10 MB.';
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($upload['tmp_name']);
        $allowedTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/bmp',
            'image/tiff'
        ];
        if (!in_array($mimeType, $allowedTypes, true)) {
            return 'Use a PDF, JPEG, PNG, WebP, BMP, or TIFF file.';
        }
        if ($mimeType !== 'application/pdf') {
            $dimensions = getimagesize($upload['tmp_name']);
            if ($dimensions === false || $dimensions[0] * $dimensions[1] > 40_000_000) {
                return 'The image is invalid or exceeds the 40-megapixel limit.';
            }
        }

        $upload['mime_type'] = $mimeType;
        return null;
    }

    private function extension_for_mime($mimeType)
    {
        $extensions = [
            'application/pdf' => '.pdf',
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'image/bmp' => '.bmp',
            'image/tiff' => '.tif'
        ];
        return $extensions[$mimeType];
    }

    private function safe_original_name($name)
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', basename((string) $name));
        $name = mb_substr($name, 0, 255, 'UTF-8');
        return $name !== '' ? $name : 'document';
    }

    private function require_archive_access()
    {
        $this->require_login();
        $this->call->model('UserModel');
        $this->call->model('TrainingCenterModel');
        $user = $this->UserModel->with_center($this->user_id());
        $allowed = $user && (int) $user['is_active'] === 1 && (
            $user['role'] === 'admin'
            || ($user['role'] === 'center_admin'
                && $user['center_code'] === TrainingCenterModel::MAIN_OFFICE_CODE
                && (int) $user['center_is_active'] === 1)
        );
        if (!$allowed) {
            $this->json_error('Only TESDA administrators and main-office staff can access the document archive.', 403);
        }
    }
}
