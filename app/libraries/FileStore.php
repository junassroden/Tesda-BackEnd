<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Private file storage under runtime/ (denied to the web by runtime/.htaccess).
 * Files are saved under a random key, never their uploaded name, and are only
 * served through an authenticated controller that has already checked access.
 *
 * Profiles say what each area accepts:
 *   student_documents  PDF and photos, 10 MB      (enrollment requirements)
 *   classroom          PDF, Office, photos, video (learning materials, submissions)
 */
class FileStore
{
    private const PROFILES = [
        'student_documents' => [
            'dir' => 'student-documents',
            'max' => 10 * 1024 * 1024,
            'types' => [
                'pdf' => ['application/pdf'],
                'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
            ],
            'hint' => 'Use a PDF, JPG, PNG or WebP file up to 10 MB.',
        ],
        'classroom' => [
            'dir' => 'classroom-files',
            'max' => 100 * 1024 * 1024,
            'types' => [
                'pdf' => ['application/pdf'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                'doc' => ['application/msword'],
                'ppt' => ['application/vnd.ms-powerpoint'],
                'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
                'gif' => ['image/gif'],
                'mp4' => ['video/mp4'],
                'webm' => ['video/webm'],
                'mov' => ['video/quicktime'],
                'txt' => ['text/plain'],
            ],
            'hint' => 'Use a PDF, DOCX, PPTX, XLSX, image, MP4/WebM video or text file up to 100 MB.',
        ],
    ];

    // Office Open XML files are zip archives; some systems report them as such.
    private const ZIP_TYPES = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
    private const OOXML = ['docx', 'pptx', 'xlsx'];
    // Legacy Office files are OLE compound documents.
    private const OLE_TYPES = ['application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office', 'application/octet-stream'];

    /**
     * Normalises $_FILES[field] into a list of single-file arrays, whether the
     * field was sent once ("file") or many times ("files[]").
     */
    public static function uploads($field)
    {
        $raw = $_FILES[$field] ?? null;
        if (!is_array($raw) || !isset($raw['name'])) {
            return [];
        }
        if (!is_array($raw['name'])) {
            return [$raw];
        }
        $files = [];
        foreach ($raw['name'] as $i => $name) {
            $files[] = [
                'name' => $name,
                'type' => $raw['type'][$i] ?? '',
                'tmp_name' => $raw['tmp_name'][$i] ?? '',
                'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $raw['size'][$i] ?? 0,
            ];
        }
        return $files;
    }

    /**
     * Checks one upload against a profile. Returns null when it is fine, or a
     * message for the user.
     */
    public static function problem($profile, array $upload)
    {
        $p = self::PROFILES[$profile];
        $name = self::clean_name($upload['name'] ?? 'file');
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return "{$name} is too large. " . $p['hint'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return "{$name} did not upload completely. Try again.";
        }
        if (!is_uploaded_file($upload['tmp_name']) || (int) $upload['size'] < 1) {
            return "{$name} could not be verified.";
        }
        if ((int) $upload['size'] > $p['max']) {
            return "{$name} is too large. " . $p['hint'];
        }
        if (self::detect($profile, $upload) === null) {
            return "{$name} is not an accepted file type. " . $p['hint'];
        }
        return null;
    }

    /**
     * Saves a checked upload. Returns [storage_key, original_name, mime_type, file_size, file_hash].
     * Throws RuntimeException when the file cannot be written.
     */
    public static function save($profile, array $upload)
    {
        $type = self::detect($profile, $upload);
        if ($type === null) {
            throw new RuntimeException('Unsupported file type.');
        }
        $dir = self::directory($profile);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('The storage folder could not be created.');
        }
        // 32 hex characters + "." + extension (up to 4) fits CHAR(36) after trimming.
        $key = bin2hex(random_bytes(16)) . '.' . substr($type['ext'], 0, 3);
        $path = $dir . DIRECTORY_SEPARATOR . $key;
        if (!move_uploaded_file($upload['tmp_name'], $path)) {
            throw new RuntimeException('The file could not be saved.');
        }
        return [
            'storage_key' => $key,
            'original_name' => self::clean_name($upload['name'] ?? ('file.' . $type['ext'])),
            'mime_type' => $type['mime'],
            'file_size' => (int) filesize($path),
            'file_hash' => hash_file('sha256', $path),
        ];
    }

    /** Full path of a stored file, or null if the key is unsafe or the file is gone. */
    public static function path($profile, $key)
    {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]{3}$/', $key)) {
            return null;
        }
        $path = self::directory($profile) . DIRECTORY_SEPARATOR . $key;
        return is_file($path) ? $path : null;
    }

    /** Removes a stored file (used to undo a save when the database insert fails). */
    public static function remove($profile, $key)
    {
        $path = self::path($profile, $key);
        if ($path !== null && !@unlink($path)) {
            error_log('Could not remove stored file ' . $key);
        }
    }

    /**
     * Streams a stored file and exits. Inline only for types browsers render
     * safely (PDF, images, video); everything else downloads.
     */
    public static function send($profile, $key, $name, $mime, $download = false)
    {
        $path = self::path($profile, $key);
        if ($path === null) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'The file is no longer available.']);
            exit;
        }
        $inlineSafe = preg_match('#^(application/pdf|image/(jpeg|png|webp|gif)|video/(mp4|webm|quicktime))$#', (string) $mime);
        $disposition = ($download || !$inlineSafe) ? 'attachment' : 'inline';
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $name) ?: 'file';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($inlineSafe ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: {$disposition}; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode((string) $name));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /** Extension + MIME type of an upload when the profile accepts it, else null. */
    private static function detect($profile, array $upload)
    {
        $types = self::PROFILES[$profile]['types'];
        $ext = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset($types[$ext]) || !is_file((string) ($upload['tmp_name'] ?? ''))) {
            return null;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']) ?: '';
        $expected = $types[$ext][0];

        if (in_array($mime, $types[$ext], true)) {
            return ['ext' => $ext, 'mime' => $expected];
        }
        // The extension decides the stored type only when the content matches its container.
        $head = (string) file_get_contents($upload['tmp_name'], false, null, 0, 8);
        if (in_array($ext, self::OOXML, true) && in_array($mime, self::ZIP_TYPES, true) && strncmp($head, "PK\x03\x04", 4) === 0) {
            return ['ext' => $ext, 'mime' => $expected];
        }
        if (in_array($ext, ['doc', 'ppt'], true) && in_array($mime, self::OLE_TYPES, true) && $head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return ['ext' => $ext, 'mime' => $expected];
        }
        if ($ext === 'mov' && $mime === 'video/mp4') {
            return ['ext' => $ext, 'mime' => 'video/mp4'];
        }
        return null;
    }

    private static function directory($profile)
    {
        return ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . self::PROFILES[$profile]['dir'];
    }

    private static function clean_name($name)
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename((string) $name)));
        return mb_substr($name !== '' ? $name : 'file', 0, 255);
    }
}
