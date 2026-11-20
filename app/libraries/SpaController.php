<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Base controller for the JSON endpoints used by the React front end.
 *
 * - Authentication uses the LavaLust session ($this->session).
 * - Requests that change data must send "X-Requested-With: XMLHttpRequest";
 *   browsers will not add that header to a cross-site form post, so together
 *   with the SameSite session cookie it blocks cross-site request forgery.
 * - The React app posts JSON. The body is copied into $_POST so the built-in
 *   $this->io->post() and the form_validation library can read it.
 * - Responses go out through $this->io.
 */
abstract class SpaController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        // Philippine time for PHP's date() and MySQL's CURRENT_TIMESTAMP alike, so
        // reviewed_at / released_at line up with created_at on any host.
        date_default_timezone_set('Asia/Manila');
        $this->db->raw("SET time_zone = '+08:00'");

        $method = $this->io->method(true);
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !$this->io->is_ajax()) {
            $this->json_error('This request must be sent by the TESDA NEXUS app.', 403);
        }

        $this->read_json_body();
    }

    /* ---------------------------------------------------------------
     * Request input
     * ------------------------------------------------------------- */

    private function read_json_body()
    {
        $contentType = (string) ($this->io->server()['CONTENT_TYPE'] ?? '');
        if (stripos($contentType, 'application/json') === false) {
            return;
        }
        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) {
            return;
        }
        // form_validation compares strings (in_list is strict), so send numbers
        // and booleans through as the strings a normal form post would contain.
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $data[$key] = $value ? '1' : '0';
            } elseif (is_int($value) || is_float($value)) {
                $data[$key] = (string) $value;
            } elseif ($value === null) {
                $data[$key] = '';
            }
        }
        $_POST = $data;
    }

    /** Uploaded file array for a field, or null. */
    protected function file($field)
    {
        return $_FILES[$field] ?? null;
    }

    /** All posted fields (JSON or form). */
    protected function input()
    {
        return !empty($_POST) ? $this->io->post() : [];
    }

    /** One posted field as a trimmed string ('' when missing). */
    protected function str($input, $key)
    {
        $value = $input[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Run form_validation rules against the posted fields and stop with a 422
     * listing the messages when any fail. Example:
     *   $this->validate(['course_name|Program name' => 'required|max_length[150]']);
     */
    protected function validate(array $rules, array $customErrors = [])
    {
        // The library's length rules call strlen() on the value, which fails for a
        // field that was never sent, so optional fields start as ''.
        foreach (array_keys($rules) as $field) {
            $name = explode('|', $field)[0];
            if (!array_key_exists($name, $_POST)) {
                $_POST[$name] = '';
            }
        }
        // Loaded here, not autoloaded: it copies $_POST when it is created, so it
        // must come after the JSON body and the defaults above are in place.
        $this->call->library('form_validation');
        if (!$this->form_validation->validate($rules, $customErrors)) {
            $this->json_errors($this->form_validation->get_errors() ?: ['Check the form and try again.']);
        }
    }

    /* ---------------------------------------------------------------
     * Responses
     * ------------------------------------------------------------- */

    protected function json($data, $code = 200)
    {
        $this->io->set_status_code($code);
        $this->io->add_header([
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], null);
        // OCR text can contain invalid UTF-8, so encode with substitution instead of send_json().
        $this->io->set_content(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->io->send();
        exit;
    }

    protected function json_error($message, $code = 400, $extra = [])
    {
        $this->json(array_merge(['error' => $message], $extra), $code);
    }

    /** Validation failures: a list of messages the form shows to the user. */
    protected function json_errors($errors, $code = 422)
    {
        $errors = array_values($errors);
        $this->json(['error' => $errors[0] ?? 'Check the form and try again.', 'errors' => $errors], $code);
    }

    /* ---------------------------------------------------------------
     * Session helpers
     * ------------------------------------------------------------- */

    protected function role()
    {
        return (string) $this->session->userdata('user_role');
    }

    protected function user_id()
    {
        return (int) $this->session->userdata('id');
    }

    protected function center_id()
    {
        return (int) $this->session->userdata('training_center_id');
    }

    protected function is_center_admin()
    {
        return $this->role() === 'center_admin';
    }

    /** Center users only see their own center's records; admins see all (null). */
    protected function center_scope()
    {
        return $this->role() === 'admin' ? null : $this->center_id();
    }

    protected function require_login()
    {
        if (!$this->session->has_userdata('id') || !$this->session->has_userdata('user_role')) {
            $this->json_error('Log in to continue.', 401);
        }
    }

    protected function require_role($roles, $message = 'This page is not available for your account role.')
    {
        $this->require_login();
        if (!in_array($this->role(), (array) $roles, true)) {
            $this->json_error($message, 403);
        }
    }

    protected function can_access_document_archive()
    {
        if ($this->role() === 'admin') {
            return true;
        }
        if (!$this->is_center_admin()) {
            return false;
        }
        $this->call->model('TrainingCenterModel');
        return $this->TrainingCenterModel->is_main_office($this->center_id());
    }

    /** The signed-in user as the front end sees it. */
    protected function session_user()
    {
        if (!$this->session->has_userdata('id')) {
            return null;
        }
        return [
            'id' => $this->user_id(),
            'username' => (string) $this->session->userdata('username'),
            'full_name' => (string) $this->session->userdata('full_name'),
            'role' => $this->role(),
            'training_center_id' => $this->center_id() ?: null,
            'training_center_name' => (string) $this->session->userdata('training_center_name'),
            'can_access_document_archive' => $this->can_access_document_archive(),
        ];
    }
}
