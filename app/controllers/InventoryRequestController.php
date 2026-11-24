<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Inventory requests from learning centers to the main office.
 *
 *   center_admin     drafts (letter, photos, items), submits, tracks, confirms receipt
 *                    — own center only
 *   admin            reviews submitted requests: approve or disapprove with remarks,
 *                    resolves receipt discrepancies — never changes stock
 *   inventory_staff  works on approved requests: checks stock, generates the QR delivery
 *                    — never changes stock
 * Stock is deducted only by delivery personnel (DeliveryController::release).
 * Every step is written to activity_logs under the request, and the people
 * concerned get a notification.
 */
class InventoryRequestController extends MY_Controller
{
    private const MAX_PHOTOS = 10;

    public function __construct()
    {
        parent::__construct();
        $this->require_role(['admin', 'center_admin', 'inventory_staff'], 'Inventory requests are for learning center administrators and main office staff.');
        $this->call->library('FileStore');
        $this->call->model('InventoryRequestModel');
        $this->call->model('InventoryDeliveryModel');
        $this->call->model('AuditModel');
        $this->call->model('NotificationModel');
    }

    /** GET /inventory-requests?status=a,b */
    public function index()
    {
        $statuses = array_values(array_intersect(InventoryRequestModel::STATUSES,
            array_filter(explode(',', (string) ($_GET['status'] ?? '')))));
        $filters = ['statuses' => $statuses];
        switch ($this->role()) {
            case 'center_admin':
                $filters['center_id'] = $this->center_id();
                break;
            case 'admin':
                $filters['exclude_draft'] = true;
                $filters['statuses'] = array_values(array_diff($statuses, ['draft']));
                break;
            case 'inventory_staff':
                $filters['statuses'] = $statuses ? array_values(array_intersect($statuses, InventoryRequestModel::AFTER_APPROVAL)) : InventoryRequestModel::AFTER_APPROVAL;
                if (!$filters['statuses']) {
                    $this->json(['requests' => []]);
                }
                break;
        }
        $this->json(['requests' => $this->InventoryRequestModel->listing($filters)]);
    }

    /** GET /inventory-requests/{id} : everything about one request. */
    public function show($id)
    {
        $this->json($this->payload($this->request_or_404((int) $id)));
    }

    /**
     * POST /inventory-requests : a new draft.
     *   { letter_mode: composed|uploaded, letter_date, letter_recipient, letter_subject, letter_body,
     *     letter_signatory, letter_position, items: [{product_id, quantity, justification}] }
     */
    public function create()
    {
        $this->require_center_admin();
        [$fields, $items] = $this->draft_input();
        $id = $this->InventoryRequestModel->save_draft(null, $this->center_id(), $this->user_id(), $fields, $items);
        $this->AuditModel->log($this->user_id(), $this->center_id(), 'drafted', 'inventory_request', $id, 'Started an inventory request draft');
        $this->json(array_merge(['message' => 'Draft saved.'], $this->payload($this->InventoryRequestModel->detail($id))), 201);
    }

    /** PUT /inventory-requests/{id} : update a draft (submitted requests cannot change). */
    public function update($id)
    {
        $request = $this->own_draft((int) $id);
        [$fields, $items] = $this->draft_input();
        $this->InventoryRequestModel->save_draft($request['id'], $this->center_id(), $this->user_id(), $fields, $items);
        $this->json(array_merge(['message' => 'Draft saved.'], $this->payload($this->InventoryRequestModel->detail($request['id']))));
    }

    /** DELETE /inventory-requests/{id} : discard a draft. */
    public function delete($id)
    {
        $request = $this->own_draft((int) $id);
        $this->InventoryRequestModel->delete_draft($request['id']);
        $this->json(['message' => 'Draft deleted.']);
    }

    /** POST /inventory-requests/{id}/files (multipart: kind=letter|attachment, files[]) */
    public function upload($id)
    {
        $request = $this->own_draft((int) $id);
        $kind = $this->str($this->input(), 'kind');
        if (!in_array($kind, ['letter', 'attachment'], true)) {
            $this->json_error('Choose whether this is the letter or a supporting photo.', 422);
        }
        $profile = $kind === 'letter' ? 'inventory_letter' : 'inventory_photo';
        $uploads = array_values(array_filter(array_merge(FileStore::uploads('files'), FileStore::uploads('file')),
            fn ($u) => (int) ($u['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if (!$uploads) {
            $this->json_error('Choose a file.', 422);
        }
        if ($kind === 'letter' && count($uploads) > 1) {
            $this->json_error('Upload one letter file.', 422);
        }
        if ($kind === 'attachment') {
            $existing = count(array_filter($this->InventoryRequestModel->files($request['id']), fn ($f) => $f['kind'] === 'attachment'));
            if ($existing + count($uploads) > self::MAX_PHOTOS) {
                $this->json_error('Attach up to ' . self::MAX_PHOTOS . ' photos.', 422);
            }
        }
        foreach ($uploads as $upload) {
            $problem = FileStore::problem($profile, $upload);
            if ($problem) {
                $this->json_error($problem, 422);
            }
        }
        try {
            foreach ($uploads as $upload) {
                $this->InventoryRequestModel->add_file($request['id'], $kind, $upload, $this->user_id());
            }
        } catch (RuntimeException $e) {
            $this->json_error('The file could not be saved. Try again.', 500);
        }
        $this->json(array_merge(['message' => $kind === 'letter' ? 'Letter attached.' : 'Photos attached.'], $this->payload($this->InventoryRequestModel->detail($request['id']))), 201);
    }

    /** DELETE /inventory-requests/{id}/files/{fileId} : remove a file from a draft. */
    public function remove_file($id, $fileId)
    {
        $request = $this->own_draft((int) $id);
        $file = $this->InventoryRequestModel->file((int) $fileId);
        if (!$file || (int) $file['request_id'] !== (int) $request['id']) {
            $this->json_error('File not found.', 404);
        }
        $this->InventoryRequestModel->remove_file($file);
        $this->json(array_merge(['message' => 'Removed.'], $this->payload($this->InventoryRequestModel->detail($request['id']))));
    }

    /** GET /inventory-request-files/{id}?download=1 : the letter or a photo, after the same access check as the request. */
    public function file($fileId)
    {
        $file = $this->InventoryRequestModel->file((int) $fileId);
        if (!$file) {
            $this->json_error('File not found.', 404);
        }
        $this->request_or_404((int) $file['request_id']);
        FileStore::send($file['kind'] === 'letter' ? 'inventory_letter' : 'inventory_photo', $file['storage_key'],
            $file['original_name'], $file['mime_type'], isset($_GET['download']));
    }

    /** POST /inventory-requests/{id}/submit : draft -> pending approval, with its reference number. */
    public function submit($id)
    {
        $request = $this->own_draft((int) $id);
        $items = $this->InventoryRequestModel->items($request['id']);
        $files = $this->InventoryRequestModel->files($request['id']);
        $problems = [];
        if ($request['letter_mode'] === 'uploaded') {
            if (!array_filter($files, fn ($f) => $f['kind'] === 'letter')) {
                $problems[] = 'Upload the request letter (PDF, DOCX or DOC).';
            }
        } elseif ($request['letter_mode'] === 'composed') {
            foreach (['letter_date' => 'date', 'letter_recipient' => 'recipient', 'letter_subject' => 'subject',
                      'letter_body' => 'body', 'letter_signatory' => 'name of the signatory'] as $key => $label) {
                if (trim((string) $request[$key]) === '') {
                    $problems[] = "Fill in the letter's {$label}.";
                }
            }
        } else {
            $problems[] = 'Write the request letter or upload one.';
        }
        if (!$items) {
            $problems[] = 'Add at least one inventory item.';
        }
        if ($problems) {
            $this->json_errors($problems);
        }
        try {
            $code = $this->InventoryRequestModel->submit($request['id']);
        } catch (DomainException $e) {
            $this->json_error($e->getMessage(), 409);
        }
        $this->AuditModel->log($this->user_id(), $this->center_id(), 'submitted', 'inventory_request', $request['id'],
            "Submitted {$code} with " . count($items) . ' item(s)');
        $this->NotificationModel->notify($this->NotificationModel->user_ids('admin'), "New inventory request {$code}",
            "{$request['center_name']} requests " . count($items) . ' item(s): ' . ($request['letter_subject'] ?: 'see the request letter') . '.',
            'inventory_request', $request['id']);
        $this->json(array_merge(['message' => "Request {$code} sent to the main office for approval."], $this->payload($this->InventoryRequestModel->detail($request['id']))));
    }

    /** POST /inventory-requests/{id}/review  { decision: approved|rejected, remarks } (main office administrator) */
    public function review($id)
    {
        $this->require_role('admin', 'Only main office administrators approve inventory requests.');
        $request = $this->request_or_404((int) $id);
        $input = $this->input();
        $decision = $this->str($input, 'decision');
        $remarks = mb_substr($this->str($input, 'remarks'), 0, 1000);
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            $this->json_error('Choose approve or disapprove.', 422);
        }
        if ($decision === 'rejected' && $remarks === '') {
            $this->json_error('Give the reason for disapproving so the learning center knows what to change.', 422);
        }
        try {
            $this->InventoryRequestModel->review($request['id'], $decision, $remarks, $this->user_id());
        } catch (DomainException $e) {
            $this->json_error($e->getMessage(), 409);
        }
        $word = $decision === 'approved' ? 'Approved' : 'Disapproved';
        $this->AuditModel->log($this->user_id(), $request['training_center_id'], $decision, 'inventory_request', $request['id'],
            "{$word} {$request['request_code']}" . ($remarks !== '' ? ": {$remarks}" : ''));
        $this->NotificationModel->notify($this->NotificationModel->user_ids('center_admin', $request['training_center_id']),
            "{$request['request_code']} " . strtolower($word),
            $decision === 'approved'
                ? 'The main office approved your inventory request. It is now with inventory personnel for preparation.'
                : "The main office disapproved your inventory request: {$remarks}",
            'inventory_request', $request['id']);
        if ($decision === 'approved') {
            $this->NotificationModel->notify($this->NotificationModel->user_ids('inventory_staff'), "{$request['request_code']} approved for processing",
                "Prepare the items requested by {$request['center_name']} and generate its QR code.", 'inventory_request', $request['id']);
        }
        $this->json(array_merge(['message' => "Request {$word}."], $this->payload($this->InventoryRequestModel->detail($request['id']))));
    }

    /** POST /inventory-requests/{id}/processing : inventory personnel start preparing an approved request. */
    public function start_processing($id)
    {
        $this->require_role('inventory_staff', 'Only inventory personnel prepare approved requests.');
        $request = $this->request_or_404((int) $id);
        if (!$this->InventoryRequestModel->start_processing($request['id'], $this->user_id())) {
            $this->json_error('Only an approved request can be moved to processing.', 409);
        }
        $this->AuditModel->log($this->user_id(), $request['training_center_id'], 'processing', 'inventory_request', $request['id'],
            "Started preparing {$request['request_code']}");
        $this->NotificationModel->notify($this->NotificationModel->user_ids('center_admin', $request['training_center_id']),
            "{$request['request_code']} is being prepared", 'Inventory personnel at the main office started preparing your items.',
            'inventory_request', $request['id']);
        $this->json(array_merge(['message' => 'Marked as processing.'], $this->payload($this->InventoryRequestModel->detail($request['id']))));
    }

    /**
     * POST /inventory-requests/{id}/qr : inventory personnel generate the QR delivery
     * after the stock check passes. Stock is NOT deducted here.
     */
    public function generate_qr($id)
    {
        $this->require_role('inventory_staff', 'Only inventory personnel generate delivery QR codes.');
        $request = $this->request_or_404((int) $id);
        try {
            $deliveryId = $this->InventoryRequestModel->generate_delivery($request, $this->user_id());
        } catch (DomainException $e) {
            $this->json_error($e->getMessage(), 409);
        }
        $delivery = $this->InventoryDeliveryModel->detail($deliveryId);
        $this->AuditModel->log($this->user_id(), $request['training_center_id'], 'qr_generated', 'inventory_request', $request['id'],
            "Generated QR code {$delivery['delivery_code']} for {$request['request_code']}: {$delivery['items_summary']}");
        $this->NotificationModel->notify($this->NotificationModel->user_ids('delivery_staff'), "{$delivery['delivery_code']} ready for delivery",
            "{$request['request_code']} to {$request['center_name']}: {$delivery['items_summary']}.", 'inventory_delivery', $deliveryId);
        $this->NotificationModel->notify($this->NotificationModel->user_ids('center_admin', $request['training_center_id']),
            "{$request['request_code']} is ready for delivery", 'Your items are prepared and waiting for release by delivery personnel.',
            'inventory_request', $request['id']);
        $this->json(array_merge(['message' => "QR code {$delivery['delivery_code']} generated. The delivery is ready."], $this->payload($this->InventoryRequestModel->detail($request['id']))), 201);
    }

    /**
     * POST /inventory-requests/{id}/receive  { items: [{id, quantity_received}], notes }
     * The learning center confirms receipt. Different quantities record a discrepancy instead of completing.
     */
    public function receive($id)
    {
        $this->require_center_admin();
        $request = $this->request_or_404((int) $id);
        $data = json_decode((string) file_get_contents('php://input'), true);
        $received = [];
        foreach ((is_array($data['items'] ?? null) ? $data['items'] : []) as $row) {
            $qty = $row['quantity_received'] ?? null;
            if (!is_numeric($qty) || (int) $qty < 0) {
                $this->json_error('Enter the quantity received for each item (0 or more).', 422);
            }
            $received[(int) ($row['id'] ?? 0)] = (int) $qty;
        }
        $notes = mb_substr(trim((string) ($data['notes'] ?? '')), 0, 2000);
        $items = $this->InventoryRequestModel->items($request['id']);
        $differs = false;
        foreach ($items as $item) {
            if (($received[$item['id']] ?? null) === null) {
                $this->json_error("Enter how many {$item['product_name']} arrived.", 422);
            }
            if ($received[$item['id']] !== (int) ($item['quantity_approved'] ?? $item['quantity_requested'])) {
                $differs = true;
            }
        }
        if ($differs && $notes === '') {
            $this->json_error('Describe the discrepancy: what was missing, extra or damaged.', 422);
        }
        try {
            $status = $this->InventoryRequestModel->receive($request, $received, $notes, $this->user_id());
        } catch (DomainException $e) {
            $this->json_error($e->getMessage(), 409);
        }
        if ($status === 'completed') {
            $this->AuditModel->log($this->user_id(), $this->center_id(), 'received', 'inventory_request', $request['id'], "Confirmed receipt of {$request['request_code']}");
            $message = 'Receipt confirmed. The request is completed.';
        } else {
            $this->AuditModel->log($this->user_id(), $this->center_id(), 'discrepancy', 'inventory_request', $request['id'],
                "Reported a discrepancy on {$request['request_code']}: {$notes}");
            $this->NotificationModel->notify($this->NotificationModel->user_ids('admin'), "Discrepancy on {$request['request_code']}",
                "{$request['center_name']} received different quantities: {$notes}", 'inventory_request', $request['id']);
            $message = 'Discrepancy reported to the main office. The request stays open until they resolve it.';
        }
        $this->json(array_merge(['message' => $message], $this->payload($this->InventoryRequestModel->detail($request['id']))));
    }

    /** POST /inventory-requests/{id}/resolve  { remarks } : the main office closes a discrepancy. */
    public function resolve($id)
    {
        $this->require_role('admin', 'Only main office administrators resolve discrepancies.');
        $request = $this->request_or_404((int) $id);
        $remarks = mb_substr($this->str($this->input(), 'remarks'), 0, 2000);
        if ($remarks === '') {
            $this->json_error('Record how the discrepancy was resolved.', 422);
        }
        if (!$this->InventoryRequestModel->resolve($request['id'], $remarks, $this->user_id())) {
            $this->json_error('Only a request with a reported discrepancy can be resolved.', 409);
        }
        $this->AuditModel->log($this->user_id(), $request['training_center_id'], 'resolved', 'inventory_request', $request['id'],
            "Resolved the discrepancy on {$request['request_code']}: {$remarks}");
        $this->NotificationModel->notify($this->NotificationModel->user_ids('center_admin', $request['training_center_id']),
            "{$request['request_code']} discrepancy resolved", $remarks, 'inventory_request', $request['id']);
        $this->json(array_merge(['message' => 'Discrepancy resolved; the request is completed.'], $this->payload($this->InventoryRequestModel->detail($request['id']))));
    }

    /* ---------------------------------------------------------------- */

    private function payload(array $request)
    {
        $delivery = $this->InventoryDeliveryModel->for_request($request['id']);
        // The QR token is only handed to the inventory personnel who print it (and the main office).
        if ($delivery && $delivery['status'] === 'ready' && in_array($this->role(), ['inventory_staff', 'admin'], true)) {
            $delivery['qr_payload'] = InventoryDeliveryModel::QR_PREFIX . $this->InventoryDeliveryModel->token($delivery['id']);
        }
        $this->call->model('LedgerModel');
        return [
            'request' => $request,
            // Blocks of the request and its delivery, checked against today's records.
            'ledger' => $this->LedgerModel->entity_status('inventory_request', $request['id'], $delivery ? [['inventory_delivery', (int) $delivery['id']]] : []),
            'items' => $this->InventoryRequestModel->items($request['id']),
            'files' => $this->InventoryRequestModel->files($request['id']),
            'delivery' => $delivery,
            'activity' => $this->AuditModel->for_entity('inventory_request', $request['id']),
        ];
    }

    /** Letter fields and items from the request body, validated. */
    private function draft_input()
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        $data = is_array($data) ? $data : [];
        $s = fn ($k, $max) => mb_substr(trim((string) ($data[$k] ?? '')), 0, $max);
        $mode = $s('letter_mode', 10);
        if (!in_array($mode, ['composed', 'uploaded'], true)) {
            $this->json_error('Choose to write the letter here or upload one.', 422);
        }
        $date = $s('letter_date', 10);
        if ($date !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                $this->json_error('Enter the letter date as YYYY-MM-DD.', 422);
            }
        }
        $fields = [
            'letter_mode' => $mode,
            'letter_date' => $date !== '' ? $date : null,
            'letter_recipient' => $s('letter_recipient', 255) ?: null,
            'letter_subject' => $s('letter_subject', 200) ?: null,
            'letter_body' => $s('letter_body', 20000) ?: null,
            'letter_signatory' => $s('letter_signatory', 150) ?: null,
            'letter_position' => $s('letter_position', 150) ?: null,
        ];
        $fields['purpose'] = $fields['letter_subject'] ?: 'Inventory request';

        $this->call->model('ProductModel');
        $items = [];
        $seen = [];
        foreach ((is_array($data['items'] ?? null) ? $data['items'] : []) as $raw) {
            $product = $this->ProductModel->find((int) ($raw['product_id'] ?? 0));
            if (!$product) {
                $this->json_error('One of the items is not in the main office inventory.', 422);
            }
            if (isset($seen[$product['id']])) {
                $this->json_error("{$product['product_name']} is listed twice. Combine the quantities.", 422);
            }
            $qty = (int) ($raw['quantity'] ?? 0);
            if ($qty < 1 || $qty > 100000) {
                $this->json_error("Enter how many {$product['product_name']} you need.", 422);
            }
            $seen[$product['id']] = true;
            $items[] = [
                'product_id' => (int) $product['id'],
                'quantity' => $qty,
                'justification' => mb_substr(trim((string) ($raw['justification'] ?? '')), 0, 500),
            ];
        }
        if (count($items) > 100) {
            $this->json_error('A request can list up to 100 items.', 422);
        }
        return [$fields, $items];
    }

    /**
     * The request if this user may see it:
     *   center_admin    own center's requests (drafts included)
     *   admin           every submitted request
     *   inventory_staff approved requests and later
     */
    private function request_or_404($id)
    {
        $request = $this->InventoryRequestModel->detail($id);
        $ok = $request && match ($this->role()) {
            'center_admin' => (int) $request['training_center_id'] === $this->center_id(),
            'admin' => $request['status'] !== 'draft',
            'inventory_staff' => in_array($request['status'], InventoryRequestModel::AFTER_APPROVAL, true),
            default => false,
        };
        if (!$ok) {
            $this->json_error('Request not found.', 404);
        }
        return $request;
    }

    private function own_draft($id)
    {
        $this->require_center_admin();
        $request = $this->request_or_404($id);
        if ($request['status'] !== 'draft') {
            $this->json_error('Submitted requests can no longer be changed.', 409);
        }
        return $request;
    }

    private function require_center_admin()
    {
        if (!$this->is_center_admin() || $this->center_id() < 1) {
            $this->json_error('Only learning center administrators can do this.', 403);
        }
    }
}
