<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Training allowance requests.
 * Learning center admins request an allowance for one batch.
 * Main office admins verify each trainee, then approve, reject, or release.
 */
class AllowanceController extends MY_Controller
{
    /** Which status a request may move to from its current one. */
    private const NEXT_STATUS = [
        'pending' => ['budget_check', 'for_approval', 'approved', 'rejected'],
        'budget_check' => ['for_approval', 'approved', 'rejected'],
        'for_approval' => ['approved', 'rejected'],
        'approved' => ['released'],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->require_role(['admin', 'center_admin'], 'Allowance requests are for main office and learning center administrators.');
        $this->call->model('AllowanceModel');
        $this->call->model('AuditModel');
    }

    /** GET /allowance-requests */
    public function index()
    {
        $this->json(['requests' => $this->AllowanceModel->listing($this->center_scope())]);
    }

    /** GET /allowance-requests/{id} */
    public function show($id)
    {
        $this->json([
            'request' => $this->request_or_404((int) $id),
            'students' => $this->AllowanceModel->students((int) $id),
        ]);
    }

    /** POST /allowance-requests  { batch_id, amount_per_student, notes } */
    public function create()
    {
        $this->require_role('center_admin', 'Only learning center administrators can request allowances.');
        $this->validate([
            'batch_id|Batch' => 'required|numeric',
            'amount_per_student|Allowance per trainee' => 'required|numeric|greater_than[0]|less_than_equal_to[100000]',
            'notes|Notes' => 'max_length[1000]',
        ], ['batch_id' => ['required' => 'Choose a batch from your center.']]);

        $input = $this->input();
        $batchId = (int) $input['batch_id'];
        $this->call->model('BatchModel');
        if (!$this->BatchModel->detail($batchId, $this->center_id())) {
            $this->json_error('Choose a batch from your center.', 422);
        }
        $open = $this->AllowanceModel->open_request_for_batch($batchId);
        if ($open) {
            $this->json_error("This batch already has an open request ({$open}).", 422);
        }

        try {
            $id = $this->AllowanceModel->submit($this->center_id(), $this->user_id(), $batchId,
                round((float) $input['amount_per_student'], 2), $this->str($input, 'notes'));
        } catch (RuntimeException $e) {
            $this->json_error($e->getMessage(), 422);
        }
        $request = $this->AllowanceModel->detail($id);
        $this->AuditModel->log($this->user_id(), $this->center_id(), 'submitted', 'allowance_request', $id,
            "Requested allowance {$request['request_code']} for {$request['batch_name']}");
        $this->json(['message' => "Request {$request['request_code']} sent to the main office.", 'request' => $request], 201);
    }

    /** PUT /allowance-requests/{id}/students/{studentId}  { checked: true|false } */
    public function check_student($id, $studentId)
    {
        $this->require_role('admin', 'Only the main office verifies trainees.');
        $request = $this->request_or_404((int) $id);
        if (in_array($request['status'], ['released', 'rejected'], true)) {
            $this->json_error("This request is already {$request['status']}.", 409);
        }
        $checked = ($this->input()['checked'] ?? '0') === '1';
        $this->AllowanceModel->set_checked((int) $id, (int) $studentId, $checked, $this->user_id());
        $this->json(['message' => 'Saved.', 'students' => $this->AllowanceModel->students((int) $id)]);
    }

    /** POST /allowance-requests/{id}/status  { status: budget_check|for_approval|approved|rejected|released, remarks } */
    public function set_status($id)
    {
        $this->require_role('admin', 'Only the main office can change an allowance request.');
        $request = $this->request_or_404((int) $id);
        $this->validate([
            'status|Status' => 'required|in_list[budget_check,for_approval,approved,rejected,released]',
            'remarks|Remarks' => 'max_length[1000]',
        ]);
        $input = $this->input();
        $status = $this->str($input, 'status');
        $remarks = $this->str($input, 'remarks');

        if (!in_array($status, self::NEXT_STATUS[$request['status']] ?? [], true)) {
            $this->json_error("A {$request['status']} request can't be changed to " . str_replace('_', ' ', $status) . '.', 409);
        }
        if ($status === 'rejected' && $remarks === '') {
            $this->json_error('Add a note so the learning center knows why it was rejected.', 422);
        }
        if (in_array($status, ['approved', 'released'], true)) {
            $unchecked = $this->AllowanceModel->unchecked_count((int) $id);
            if ($unchecked > 0) {
                $this->json_error("Verify every trainee first ({$unchecked} left).", 422);
            }
        }

        if ($status === 'released') {
            $this->AllowanceModel->release((int) $id, $this->user_id(), $this->AuditModel);
        } else {
            $this->AllowanceModel->review((int) $id, $status, $remarks, $this->user_id());
        }
        $this->AuditModel->log($this->user_id(), $request['training_center_id'], $status, 'allowance_request', (int) $id,
            ucfirst(str_replace('_', ' ', $status)) . " {$request['request_code']}");
        $this->json(['message' => 'Request updated.', 'request' => $this->AllowanceModel->detail((int) $id)]);
    }

    /** The request with names, or a 404. Center admins only see their own center's requests. */
    private function request_or_404($id)
    {
        $request = $this->AllowanceModel->detail($id);
        $scope = $this->center_scope();
        if (!$request || ($scope && (int) $request['training_center_id'] !== $scope)) {
            $this->json_error('Allowance request not found.', 404);
        }
        return $request;
    }
}
