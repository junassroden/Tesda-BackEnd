<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Base controller for the JSON endpoints used by the React front end.
 *
 * Authentication uses the existing LavaLust session cookie. Requests that
 * change data must send "X-Requested-With: XMLHttpRequest"; browsers will not
 * add that header to a cross-site form post, so together with the SameSite
 * session cookie it blocks cross-site request forgery.
 */
abstract class SpaController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
            && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
            $this->json_error('This request must be sent by the TESDA NEXUS app.', 403);
        }
    }

    protected function json($data, $code = 200)
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    protected function json_error($message, $code = 400, $extra = [])
    {
        $this->json(array_merge(['error' => $message], $extra), $code);
    }

    /** Validation failures: a list of messages the form shows to the user. */
    protected function json_errors($errors, $code = 422)
    {
        $this->json(['error' => $errors[0] ?? 'Check the form and try again.', 'errors' => array_values($errors)], $code);
    }

    /** Raw request body. React escapes output, so input is stored as typed. */
    protected function input()
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode((string) file_get_contents('php://input'), true);
            return is_array($data) ? $data : [];
        }
        return $_POST;
    }

    protected function str($input, $key)
    {
        $value = $input[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

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
        $center = $this->db->raw(
            "SELECT id FROM training_centers WHERE id = ? AND center_code = 'TESDA-MAIN' AND is_active = 1 LIMIT 1",
            [$this->center_id()]
        )->fetch(PDO::FETCH_ASSOC);
        return (bool) $center;
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
            'can_access_document_archive' => $this->can_access_document_archive()
        ];
    }
}
