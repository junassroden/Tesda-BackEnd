<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** GET /summary : dashboard numbers for the main office (all centers) or one learning center. */
class SummaryController extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(['admin', 'center_admin'], 'The dashboard is for main office and learning center administrators.');
        $this->call->model('InventoryRequestModel');
        $this->call->model('AllowanceModel');
        $this->call->model('BatchModel');
        $this->call->model('AuditModel');
    }

    public function index()
    {
        $scope = $this->center_scope();
        if ($scope) {
            $this->BatchModel->sweep();
        }
        $batches = $scope ? $this->BatchModel->by_center($scope) : [];
        $training = null;
        if ($scope) {
            $this->call->model('StudentDocumentModel');
            $training = [
                'needs_review' => count(array_filter($batches, fn ($b) => $b['needs_review'])),
                'suspended' => count(array_filter($batches, fn ($b) => $b['status'] === 'suspended')),
                'ongoing' => count(array_filter($batches, fn ($b) => $b['status'] === 'ongoing')),
                'pending_documents' => $this->StudentDocumentModel->pending_count($scope),
            ];
        }
        $this->json([
            'training' => $training,
            'inventory_requests' => [
                'by_status' => $this->InventoryRequestModel->status_counts($scope),
                'recent' => $this->InventoryRequestModel->listing(['center_id' => $scope, 'limit' => 4, 'exclude_draft' => !$scope]),
            ],
            'allowance' => [
                'by_status' => $this->AllowanceModel->status_totals($scope),
                'recent' => $this->AllowanceModel->listing($scope, 4),
            ],
            'batches' => $batches,
            'activity' => $this->AuditModel->recent($scope, 6),
        ]);
    }
}
