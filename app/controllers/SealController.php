<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * QR-verifiable printed documents.
 *   POST /seals                  staff: the seal (QR token) for a sheet being printed
 *   GET  /public/verify/{token}  anyone: is this printed sheet authentic and current?
 */
class SealController extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('SealModel');
    }

    /**
     * POST /seals  { kind, entity_id }
     * Student sheets: the learning center of the student. Batch records: that
     * center, or the main office. Answers with the token the QR code points to.
     */
    public function issue()
    {
        $this->require_role(['center_admin', 'admin'], 'Only learning center and main office administrators print sealed records.');
        $input = $this->input();
        $kind = $this->str($input, 'kind');
        $entityId = (int) ($input['entity_id'] ?? 0);
        if (!isset(SealModel::KINDS[$kind])) {
            $this->json_error('Unknown document type.', 422);
        }
        $centerId = $this->SealModel->center_of($kind, $entityId);
        $allowed = $centerId !== null && (
            ($this->is_center_admin() && $centerId === $this->center_id())
            || ($this->role() === 'admin' && $kind === 'batch_records')
        );
        if (!$allowed) {
            $this->json_error('Record not found.', 404);
        }
        $seal = $this->SealModel->issue($kind, $entityId, $this->user_id());
        $this->json(['seal' => [
            'token' => $seal['token'],
            'issued_at' => $seal['issued_at'],
            'fingerprint' => $seal['snapshot_hash'],
            'block_index' => $seal['block_index'] === null ? null : (int) $seal['block_index'],
        ]]);
    }

    /** GET /public/verify/{token} : no login; answers only what is needed to check the paper. */
    public function verify($token)
    {
        $this->json($this->SealModel->verify_public((string) $token));
    }
}
