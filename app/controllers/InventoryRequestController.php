<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

/**
 * Supply requests.
 * Learning center admins create requests and mark them received.
 * Main office admins review (in review / approve / reject) and release them.
 */
class InventoryRequestController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(['admin', 'center_admin'], 'Supply requests are for main office and learning center administrators.');
        $this->call->model('InventoryRequestModel');
        $this->call->model('AuditModel');
    }

    /** GET /inventory-requests */
    public function index()
    {
        $this->json(['requests' => $this->InventoryRequestModel->listing($this->center_scope())]);
    }

    /** GET /inventory-requests/{id} */
    public function show($id)
    {
        $this->json([
            'request' => $this->request_or_404((int) $id),
            'items' => $this->InventoryRequestModel->items((int) $id),
        ]);
    }

    /** POST /inventory-requests  { items: [{product_id, quantity}], delivery_personnel_id, purpose } */
    public function create()
    {
        $this->require_role('center_admin', 'Only learning center administrators can send supply requests.');
        $this->validate([
            'purpose|Purpose' => 'required|max_length[1000]',
            'delivery_personnel_id|Delivery personnel' => 'numeric',
        ], ['purpose' => ['required' => 'Say what the items are for.']]);

        $this->call->model('ProductModel');
        $input = $this->input();
        $rawItems = is_array($input['items'] ?? null) ? $input['items'] : [];
        $errors = [];
        $items = [];
        foreach ($rawItems as $raw) {
            $product = $this->ProductModel->find((int) ($raw['product_id'] ?? 0));
            $qty = (int) ($raw['quantity'] ?? 0);
            if (!$product) {
                $errors[] = 'One of the requested items no longer exists.';
                continue;
            }
            if ($qty < 1) {
                $errors[] = "Enter how many {$product['product_name']} you need.";
            } elseif ($qty > (int) $product['quantity']) {
                $errors[] = "Only {$product['quantity']} {$product['product_name']} are in stock.";
            }
            $items[] = ['product_id' => (int) $product['id'], 'quantity' => $qty];
        }
        if (!$items && !$errors) {
            $errors[] = 'Add at least one item to the request.';
        }

        $deliveryId = (int) ($input['delivery_personnel_id'] ?? 0);
        if ($deliveryId) {
            $this->call->model('DeliveryPersonnelModel');
            if (!$this->DeliveryPersonnelModel->active_in_center($deliveryId, $this->center_id())) {
                $errors[] = 'Choose delivery personnel from your center.';
            }
        }
        if ($errors) {
            $this->json_errors($errors);
        }

        $id = $this->InventoryRequestModel->submit($this->center_id(), $this->user_id(), $deliveryId, $this->str($input, 'purpose'), $items);
        $request = $this->InventoryRequestModel->find($id);
        $this->AuditModel->log($this->user_id(), $this->center_id(), 'submitted', 'inventory_request', $id, "Submitted {$request['request_code']}");
        $this->json(['message' => "Request {$request['request_code']} sent to the main office.", 'request' => $request], 201);
    }

    /** POST /inventory-requests/{id}/review  { decision: in_review|approved|rejected, remarks } */
    public function review($id)
    {
        $this->require_role('admin', 'Only the main office can review supply requests.');
        $request = $this->request_or_404((int) $id);
        $this->validate(
            ['decision|Decision' => 'required|in_list[in_review,approved,rejected]', 'remarks|Remarks' => 'max_length[1000]'],
            ['decision' => ['required' => 'Choose approve, reject, or mark in review.', 'in_list' => 'Choose approve, reject, or mark in review.']]
        );
        $input = $this->input();
        $decision = $this->str($input, 'decision');
        $remarks = $this->str($input, 'remarks');

        if (!in_array($request['status'], ['pending', 'in_review'], true)) {
            $this->json_error("This request is already {$request['status']}.", 409);
        }
        if ($decision === 'rejected' && $remarks === '') {
            $this->json_error('Add a note so the learning center knows why it was rejected.', 422);
        }

        $this->InventoryRequestModel->review((int) $id, $decision, $remarks, $this->user_id());
        $this->AuditModel->log($this->user_id(), $request['training_center_id'], $decision, 'inventory_request', (int) $id,
            ucfirst(str_replace('_', ' ', $decision)) . " {$request['request_code']}");
        $this->json(['message' => 'Request updated.', 'request' => $this->InventoryRequestModel->detail((int) $id)]);
    }

    /** POST /inventory-requests/{id}/release : takes the items out of stock and records it on the ledger. */
    public function release($id)
    {
        $this->require_role('admin', 'Only the main office can release supplies.');
        $request = $this->request_or_404((int) $id);
        if ($request['status'] !== 'approved') {
            $this->json_error('Only approved requests can be released.', 409);
        }

        $this->call->model('ProductModel');
        try {
            $this->InventoryRequestModel->release((int) $id, $this->user_id(), $this->ProductModel, $this->AuditModel);
        } catch (RuntimeException $e) {
            $this->json_error($e->getMessage(), 409);
        }
        $this->AuditModel->log($this->user_id(), $request['training_center_id'], 'released', 'inventory_request', (int) $id, "Released {$request['request_code']}");
        $this->json(['message' => 'Items released and recorded.', 'request' => $this->InventoryRequestModel->detail((int) $id)]);
    }

    /** POST /inventory-requests/{id}/received : the learning center confirms delivery. */
    public function received($id)
    {
        $this->require_role('center_admin', 'Only the learning center can confirm delivery.');
        $request = $this->request_or_404((int) $id);
        if ($request['status'] !== 'released') {
            $this->json_error('Only released requests can be marked received.', 409);
        }
        $this->InventoryRequestModel->mark_received((int) $id);
        $this->AuditModel->log($this->user_id(), $this->center_id(), 'received', 'inventory_request', (int) $id, "Received {$request['request_code']}");
        $this->json(['message' => 'Marked as received.', 'request' => $this->InventoryRequestModel->detail((int) $id)]);
    }

    /** The request with names, or a 404. Center admins only see their own center's requests. */
    private function request_or_404($id)
    {
        $request = $this->InventoryRequestModel->detail($id);
        $scope = $this->center_scope();
        if (!$request || ($scope && (int) $request['training_center_id'] !== $scope)) {
            $this->json_error('Request not found.', 404);
        }
        return $request;
    }
}
