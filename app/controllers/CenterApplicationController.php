<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

class CenterApplicationController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role('center_admin');
        $this->call->model('CenterApplicationModel');
    }

    public function index()
    {
        $this->json([
            'applications' => $this->CenterApplicationModel->pending_for_center($this->center_id())
        ]);
    }

    public function approve($id)
    {
        $this->review($id, 'approved');
    }

    public function reject($id)
    {
        $this->review($id, 'rejected');
    }

    private function review($id, $decision)
    {
        $reviewed = $this->CenterApplicationModel->review(
            (int) $id,
            $this->center_id(),
            $this->user_id(),
            $decision
        );
        if (!$reviewed) {
            $this->json_error('That application is no longer pending or does not belong to this center.', 409);
        }
        $this->json(['message' => 'Application ' . $decision . '.']);
    }
}
