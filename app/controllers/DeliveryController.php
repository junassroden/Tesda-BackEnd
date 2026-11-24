<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Delivery Management (main office).
 *   delivery_staff  sees prepared deliveries, scans and validates QR codes, confirms
 *                   the release (the ONLY place stock is deducted) and the hand-over
 *   admin           reads deliveries for monitoring
 */
class DeliveryController extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(['delivery_staff', 'admin'], 'Delivery Management is for main office delivery personnel.');
        $this->call->model('InventoryDeliveryModel');
        $this->call->model('AuditModel');
        $this->call->model('NotificationModel');
    }

    /** GET /deliveries?status=ready,in_transit */
    public function index()
    {
        $statuses = array_values(array_intersect(['ready', 'in_transit', 'delivered', 'cancelled'],
            array_filter(explode(',', (string) ($_GET['status'] ?? '')))));
        $this->json([
            'deliveries' => $this->InventoryDeliveryModel->listing($statuses),
            'counts' => $this->InventoryDeliveryModel->status_counts(),
        ]);
    }

    /** GET /deliveries/{id} */
    public function show($id)
    {
        $delivery = $this->delivery_or_404((int) $id);
        $this->call->model('LedgerModel');
        $this->json([
            'delivery' => $delivery,
            'problems' => $delivery['status'] === 'ready' ? $this->InventoryDeliveryModel->problems($delivery) : [],
            'ledger' => $this->LedgerModel->entity_status('inventory_delivery', (int) $delivery['id']),
        ]);
    }

    /**
     * POST /deliveries/scan  { code }
     * Validates a scanned QR payload, or a typed delivery reference number.
     * Answers with the delivery and why it cannot be released (if it cannot).
     */
    public function scan()
    {
        $this->require_role('delivery_staff', 'Only delivery personnel scan delivery QR codes.');
        $code = mb_substr($this->str($this->input(), 'code'), 0, 200);
        if ($code === '') {
            $this->json_error('Scan a QR code or enter the delivery reference number.', 422);
        }
        $found = $this->InventoryDeliveryModel->lookup($code);
        if (!$found['via']) {
            $this->json_error('This is not a TESDA NEXUS delivery QR code or reference number.', 422, ['valid' => false]);
        }
        if (!$found['delivery']) {
            $this->json_error($found['via'] === 'qr' ? 'This QR code does not exist. It may have been printed for another system.' : 'No delivery has that reference number.', 404, ['valid' => false]);
        }
        $delivery = $found['delivery'];
        $problems = $this->InventoryDeliveryModel->problems($delivery);
        $this->AuditModel->log($this->user_id(), $delivery['training_center_id'], 'scanned', 'inventory_request', (int) $delivery['request_id'],
            "Scanned {$delivery['delivery_code']} (" . ($found['via'] === 'qr' ? 'QR code' : 'reference number') . ')' . ($problems ? ': not valid' : ''));
        $this->json([
            'valid' => !$problems,
            'via' => $found['via'],
            'problems' => $problems,
            'delivery' => $delivery,
        ]);
    }

    /**
     * POST /deliveries/{id}/release  { code, confirm: 1 }
     * Confirms the release for delivery: deducts stock once, records the
     * movements, marks the QR code used and the delivery in transit.
     * `code` must be what was scanned or typed for this delivery.
     */
    public function release($id)
    {
        $this->require_role('delivery_staff', 'Only delivery personnel release inventory for delivery.');
        $delivery = $this->delivery_or_404((int) $id);
        $input = $this->input();
        if ($this->str($input, 'confirm') !== '1') {
            $this->json_error('Confirm that the inventory is being released for delivery.', 422);
        }
        $found = $this->InventoryDeliveryModel->lookup($this->str($input, 'code'));
        if (!$found['delivery'] || (int) $found['delivery']['id'] !== (int) $delivery['id']) {
            $this->json_error('Scan this delivery\'s QR code (or enter its reference number) before releasing it.', 422);
        }
        $problems = $this->InventoryDeliveryModel->problems($delivery);
        if ($problems) {
            $this->json_error($problems[0], 409, ['problems' => $problems]);
        }
        $this->call->model('ProductModel');
        try {
            $this->InventoryDeliveryModel->release($delivery, $this->user_id(), $this->ProductModel, $this->AuditModel);
        } catch (DomainException $e) {
            $this->json_error($e->getMessage(), 409);
        }
        $delivery = $this->InventoryDeliveryModel->detail($delivery['id']);
        $this->AuditModel->log($this->user_id(), $delivery['training_center_id'], 'released', 'inventory_request', (int) $delivery['request_id'],
            "Released {$delivery['delivery_code']} for delivery: {$delivery['items_summary']} deducted from stock");
        $this->NotificationModel->notify($this->NotificationModel->user_ids('center_admin', $delivery['training_center_id']),
            "{$delivery['request_code']} is on its way", "Released by {$delivery['released_by_name']}: {$delivery['items_summary']}.",
            'inventory_request', (int) $delivery['request_id']);
        $this->json(['message' => "Released. {$delivery['delivery_code']} is in transit and the stock was updated.", 'delivery' => $delivery]);
    }

    /** POST /deliveries/{id}/delivered : the items were handed over at the learning center. */
    public function delivered($id)
    {
        $this->require_role('delivery_staff', 'Only delivery personnel mark deliveries as delivered.');
        $delivery = $this->delivery_or_404((int) $id);
        try {
            $this->InventoryDeliveryModel->mark_delivered($delivery, $this->user_id());
        } catch (DomainException $e) {
            $this->json_error($e->getMessage(), 409);
        }
        $this->AuditModel->log($this->user_id(), $delivery['training_center_id'], 'delivered', 'inventory_request', (int) $delivery['request_id'],
            "Delivered {$delivery['delivery_code']} to {$delivery['center_name']}");
        $this->NotificationModel->notify($this->NotificationModel->user_ids('center_admin', $delivery['training_center_id']),
            "{$delivery['request_code']} delivered", 'Check the items and confirm receipt in Inventory requests.',
            'inventory_request', (int) $delivery['request_id']);
        $this->json(['message' => 'Marked as delivered.', 'delivery' => $this->InventoryDeliveryModel->detail($delivery['id'])]);
    }

    private function delivery_or_404($id)
    {
        $delivery = $this->InventoryDeliveryModel->detail($id);
        if (!$delivery) {
            $this->json_error('Delivery not found.', 404);
        }
        return $delivery;
    }
}
