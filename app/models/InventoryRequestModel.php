<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * inventory_requests (+ inventory_request_items, inventory_request_files):
 * a learning center's formal request for main office inventory.
 *
 * Lifecycle (see database/inventory_delivery.sql):
 *   draft -> pending -> approved -> processing -> ready_for_delivery
 *         -> in_transit -> delivered -> completed
 *   pending -> rejected (shown as "Disapproved")
 *   in_transit/delivered -> discrepancy -> completed (resolved by the main office)
 *
 * Approval and QR generation never change stock. Stock leaves only through
 * InventoryDeliveryModel::release(), after a delivery's QR code is validated.
 */
class InventoryRequestModel extends Model
{
    protected $table = 'inventory_requests';
    protected $primary_key = 'id';
    protected $fillable = [
        'request_code', 'training_center_id', 'requested_by', 'delivery_personnel_id', 'purpose',
        'letter_mode', 'letter_date', 'letter_recipient', 'letter_subject', 'letter_body', 'letter_signatory',
        'letter_position', 'submitted_at', 'status', 'admin_remarks', 'reviewed_by', 'reviewed_at',
        'processing_by', 'processing_at', 'released_at', 'received_at', 'received_by', 'receipt_notes',
        'resolved_by', 'resolved_at', 'resolution_notes',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->call->library('FileStore'); // LavaLust loader (app/libraries/FileStore.php)
    }

    public const STATUSES = [
        'draft', 'pending', 'approved', 'rejected', 'processing', 'ready_for_delivery',
        'in_transit', 'delivered', 'completed', 'discrepancy', 'cancelled',
    ];
    /** What inventory personnel work on. */
    public const PROCESSING = ['approved', 'processing', 'ready_for_delivery'];
    /** Approved and later: everything after the main office said yes. */
    public const AFTER_APPROVAL = ['approved', 'processing', 'ready_for_delivery', 'in_transit', 'delivered', 'completed', 'discrepancy'];

    /**
     * Requests newest first with an items summary.
     * filters: center_id, statuses (array), exclude_draft (bool), limit.
     */
    public function listing(array $filters = [])
    {
        $query = $this->db->table('inventory_requests r')
            ->select('r.id, r.request_code, r.status, r.purpose, r.letter_subject, r.created_at, r.submitted_at,
                      r.reviewed_at, r.processing_at, r.released_at, r.received_at, r.training_center_id,
                      tc.name AS center_name, u.full_name AS requested_by_name, rv.full_name AS reviewed_by_name')
            ->inner_join('training_centers tc', 'tc.id = r.training_center_id')
            ->left_join('users u', 'u.id = r.requested_by')
            ->left_join('users rv', 'rv.id = r.reviewed_by');
        if (!empty($filters['center_id'])) {
            $query->where('r.training_center_id', (int) $filters['center_id']);
        }
        if (!empty($filters['statuses'])) {
            $query->in('r.status', $filters['statuses']);
        } elseif (!empty($filters['exclude_draft'])) {
            $query->where('r.status', '!=', 'draft');
        }
        $query->order_by('r.id', 'DESC');
        if (!empty($filters['limit'])) {
            $query->limit((int) $filters['limit']);
        }
        return $this->attach_summaries($query->get_all());
    }

    /** One request with every person involved and its active delivery. */
    public function detail($id)
    {
        $request = $this->db->table('inventory_requests r')
            ->select('r.id, r.request_code, r.training_center_id, r.requested_by, r.purpose, r.letter_mode, r.letter_date,
                      r.letter_recipient, r.letter_subject, r.letter_body, r.letter_signatory, r.letter_position,
                      r.submitted_at, r.status, r.admin_remarks, r.reviewed_by, r.reviewed_at, r.processing_at,
                      r.released_at, r.received_at, r.receipt_notes, r.resolved_at, r.resolution_notes, r.created_at,
                      r.updated_at, tc.name AS center_name, tc.address AS center_address,
                      u.full_name AS requested_by_name, rv.full_name AS reviewed_by_name,
                      pr.full_name AS processing_by_name, rc.full_name AS received_by_name, rs.full_name AS resolved_by_name')
            ->inner_join('training_centers tc', 'tc.id = r.training_center_id')
            ->left_join('users u', 'u.id = r.requested_by')
            ->left_join('users rv', 'rv.id = r.reviewed_by')
            ->left_join('users pr', 'pr.id = r.processing_by')
            ->left_join('users rc', 'rc.id = r.received_by')
            ->left_join('users rs', 'rs.id = r.resolved_by')
            ->where('r.id', (int) $id)
            ->get();
        return $request ?: null;
    }

    /**
     * Items with stock figures:
     *   stock      units on hand at the main office
     *   committed  units held by OTHER requests' QR deliveries not yet released
     *   available  stock - committed (what this request can still be given)
     */
    public function items($requestId)
    {
        $items = $this->db->table('inventory_request_items i')
            ->select('i.id, i.product_id, i.quantity_requested, i.quantity_approved, i.quantity_received, i.justification,
                      p.product_name, p.unit, p.category, p.quantity AS stock')
            ->inner_join('products p', 'p.id = i.product_id')
            ->where('i.request_id', (int) $requestId)
            ->order_by('p.product_name', 'ASC')
            ->get_all();
        if (!$items) {
            return [];
        }
        $committed = $this->committed(array_column($items, 'product_id'), (int) $requestId);
        foreach ($items as &$item) {
            $item['stock'] = (int) $item['stock'];
            $item['committed'] = (int) ($committed[$item['product_id']] ?? 0);
            $item['available'] = max(0, $item['stock'] - $item['committed']);
            $needed = (int) ($item['quantity_approved'] ?? $item['quantity_requested']);
            $item['enough'] = $item['available'] >= $needed;
        }
        return $items;
    }

    /** Units of each product held by ready (not yet released) deliveries of other requests. */
    public function committed(array $productIds, $exceptRequestId = 0, $forUpdate = false)
    {
        if (!$productIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $productIds));
        $rows = $this->db->raw(
            "SELECT di.product_id, SUM(di.quantity) AS units
               FROM inventory_delivery_items di
              INNER JOIN inventory_deliveries d ON d.id = di.delivery_id
              WHERE d.status = 'ready' AND d.request_id <> ? AND di.product_id IN ($in)
              GROUP BY di.product_id" . ($forUpdate ? ' FOR UPDATE' : ''),
            [(int) $exceptRequestId]
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map('intval', array_column($rows, 'units', 'product_id'));
    }

    public function files($requestId)
    {
        return $this->db->table('inventory_request_files f')
            ->select('f.id, f.kind, f.original_name, f.mime_type, f.file_size, f.created_at, u.full_name AS uploaded_by_name')
            ->left_join('users u', 'u.id = f.uploaded_by')
            ->where('f.request_id', (int) $requestId)
            ->order_by('f.id', 'ASC')
            ->get_all();
    }

    public function file($fileId)
    {
        return $this->db->table('inventory_request_files f')
            ->select('f.id, f.request_id, f.kind, f.original_name, f.storage_key, f.mime_type, f.file_size,
                      r.training_center_id, r.status AS request_status')
            ->inner_join('inventory_requests r', 'r.id = f.request_id')
            ->where('f.id', (int) $fileId)
            ->get() ?: null;
    }

    /**
     * Creates or updates a draft with its letter fields and items.
     * items: [['product_id' => int, 'quantity' => int, 'justification' => string]].
     */
    public function save_draft($id, $centerId, $userId, array $fields, array $items)
    {
        $this->db->transaction();
        try {
            if ($id) {
                $this->update($id, $fields);
                $this->db->table('inventory_request_items')->where('request_id', (int) $id)->delete();
            } else {
                $code = 'DRAFT-' . strtoupper(bin2hex(random_bytes(5)));
                $id = (int) $this->insert(array_merge($fields, [
                    'request_code' => $code,
                    'training_center_id' => (int) $centerId,
                    'requested_by' => $userId,
                    'status' => 'draft',
                ]));
            }
            foreach ($items as $item) {
                $this->db->table('inventory_request_items')->insert([
                    'request_id' => (int) $id,
                    'product_id' => $item['product_id'],
                    'quantity_requested' => $item['quantity'],
                    'justification' => $item['justification'] !== '' ? $item['justification'] : null,
                ]);
            }
            $this->db->commit();
            return (int) $id;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** Stores a letter (replacing any earlier one) or a supporting photo for a draft. */
    public function add_file($requestId, $kind, array $upload, $userId)
    {
        $profile = $kind === 'letter' ? 'inventory_letter' : 'inventory_photo';
        $saved = FileStore::save($profile, $upload);
        $this->db->transaction();
        try {
            $old = [];
            if ($kind === 'letter') {
                $old = $this->db->table('inventory_request_files')->select('id, storage_key')
                    ->where(['request_id' => (int) $requestId, 'kind' => 'letter'])->get_all();
                $this->db->table('inventory_request_files')->where(['request_id' => (int) $requestId, 'kind' => 'letter'])->delete();
            }
            $this->db->table('inventory_request_files')->insert(array_merge($saved, [
                'request_id' => (int) $requestId, 'kind' => $kind, 'uploaded_by' => $userId,
            ]));
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            FileStore::remove($profile, $saved['storage_key']);
            throw $e;
        }
        foreach ($old as $file) {
            FileStore::remove('inventory_letter', $file['storage_key']);
        }
    }

    public function remove_file(array $file)
    {
        $this->db->table('inventory_request_files')->where('id', (int) $file['id'])->delete();
        FileStore::remove($file['kind'] === 'letter' ? 'inventory_letter' : 'inventory_photo', $file['storage_key']);
    }

    /** Deletes a draft and its files. */
    public function delete_draft($id)
    {
        $files = $this->db->table('inventory_request_files')->select('kind, storage_key')->where('request_id', (int) $id)->get_all();
        $this->delete((int) $id);
        foreach ($files as $f) {
            FileStore::remove($f['kind'] === 'letter' ? 'inventory_letter' : 'inventory_photo', $f['storage_key']);
        }
    }

    /** Draft -> pending with its permanent reference number (REQ-0001, ...). Returns the code. */
    public function submit($id)
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $code = $this->next_code();
            $taken = $this->db->table($this->table)->where('request_code', $code)->get();
            if ($taken) {
                continue;
            }
            $this->db->transaction();
            try {
                $changed = $this->db->table($this->table)
                    ->where(['id' => (int) $id, 'status' => 'draft'])
                    ->update(['request_code' => $code, 'status' => 'pending', 'submitted_at' => date('Y-m-d H:i:s')]);
                if (!$changed) {
                    throw new DomainException('This request was already submitted.');
                }
                $request = $this->db->table($this->table)->select('requested_by')->where('id', (int) $id)->get();
                $this->call->model('AuditModel');
                $this->AuditModel->record('inventory_request', (int) $id, 'submitted', $request['requested_by'] ?? null);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->roll_back();
                throw $e;
            }
            return $code;
        }
        throw new RuntimeException('Could not assign a reference number. Try again.');
    }

    /** approved / rejected by the main office. Approval approves every requested quantity. */
    public function review($id, $decision, $remarks, $reviewerId)
    {
        $this->db->transaction();
        try {
            $changed = $this->db->table($this->table)
                ->where(['id' => (int) $id, 'status' => 'pending'])
                ->update([
                    'status' => $decision,
                    'admin_remarks' => $remarks !== '' ? $remarks : null,
                    'reviewed_by' => $reviewerId,
                    'reviewed_at' => date('Y-m-d H:i:s'),
                ]);
            if (!$changed) {
                throw new DomainException('This request was already reviewed.');
            }
            if ($decision === 'approved') {
                $this->db->raw('UPDATE inventory_request_items SET quantity_approved = quantity_requested WHERE request_id = ?', [(int) $id]);
            }
            $this->call->model('AuditModel');
            $this->AuditModel->record('inventory_request', (int) $id, $decision, $reviewerId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function start_processing($id, $userId)
    {
        return $this->db->table($this->table)
            ->where(['id' => (int) $id, 'status' => 'approved'])
            ->update(['status' => 'processing', 'processing_by' => $userId, 'processing_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Generates the request's QR delivery: checks every item against stock
     * (minus what other QR deliveries already hold) with the product rows
     * locked, then stores the delivery, its items and a random token, and
     * marks the request ready for delivery. Throws DomainException listing
     * the shortfalls when stock is not enough. Returns the delivery id.
     */
    public function generate_delivery(array $request, $userId)
    {
        $this->db->transaction();
        try {
            $locked = $this->db->raw('SELECT id, status FROM inventory_requests WHERE id = ? FOR UPDATE', [(int) $request['id']])
                ->fetch(PDO::FETCH_ASSOC);
            if (!$locked || !in_array($locked['status'], ['approved', 'processing'], true)) {
                throw new DomainException('This request is not waiting for a QR code.');
            }
            $active = $this->db->table('inventory_deliveries')->where('active_request_id', (int) $request['id'])->get();
            if ($active) {
                throw new DomainException("This request already has an active QR code ({$active['delivery_code']}).");
            }

            $items = $this->db->table('inventory_request_items')
                ->select('product_id, quantity_requested, quantity_approved')
                ->where('request_id', (int) $request['id'])->get_all();
            if (!$items) {
                throw new DomainException('This request has no items.');
            }
            $ids = array_map('intval', array_column($items, 'product_id'));
            // Lock the products so two QR codes cannot both count the same units.
            $stock = $this->db->raw(
                'SELECT id, product_name, unit, quantity FROM products WHERE id IN (' . implode(',', $ids) . ') FOR UPDATE'
            )->fetchAll(PDO::FETCH_ASSOC);
            $stock = array_column($stock, null, 'id');
            $committed = $this->committed($ids, (int) $request['id'], true);

            $problems = [];
            foreach ($items as $item) {
                $p = $stock[$item['product_id']] ?? null;
                $need = (int) ($item['quantity_approved'] ?? $item['quantity_requested']);
                if (!$p) {
                    $problems[] = 'An item of this request is no longer in the main office inventory.';
                    continue;
                }
                $available = (int) $p['quantity'] - (int) ($committed[$item['product_id']] ?? 0);
                if ($need > $available) {
                    $problems[] = sprintf('%s: %d needed, %d available%s.', $p['product_name'], $need, max(0, $available),
                        ($committed[$item['product_id']] ?? 0) ? ' (others are held for prepared deliveries)' : '');
                }
            }
            if ($problems) {
                throw new DomainException(implode(' ', $problems));
            }

            $code = $this->next_delivery_code();
            $this->db->table('inventory_deliveries')->insert([
                'delivery_code' => $code,
                'request_id' => (int) $request['id'],
                'active_request_id' => (int) $request['id'],
                'qr_token' => bin2hex(random_bytes(32)),
                'status' => 'ready',
                'prepared_by' => $userId,
                'prepared_at' => date('Y-m-d H:i:s'),
            ]);
            $deliveryId = (int) $this->db->last_id();
            foreach ($items as $item) {
                $this->db->table('inventory_delivery_items')->insert([
                    'delivery_id' => $deliveryId,
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (int) ($item['quantity_approved'] ?? $item['quantity_requested']),
                ]);
            }
            $update = ['status' => 'ready_for_delivery'];
            if ($locked['status'] === 'approved') {
                $update += ['processing_by' => $userId, 'processing_at' => date('Y-m-d H:i:s')];
            }
            $this->db->table($this->table)->where('id', (int) $request['id'])->update($update);
            $this->call->model('AuditModel');
            $this->AuditModel->record('inventory_delivery', $deliveryId, 'generated', $userId);
            $this->db->commit();
            return $deliveryId;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /**
     * The learning center confirms receipt. received: [itemId => quantity].
     * Matching quantities complete the request; any difference records a
     * discrepancy for the main office instead. Returns the new status.
     */
    public function receive(array $request, array $received, $notes, $userId)
    {
        $items = $this->db->table('inventory_request_items')
            ->select('id, quantity_requested, quantity_approved')
            ->where('request_id', (int) $request['id'])->get_all();
        $matches = true;
        foreach ($items as $item) {
            $expected = (int) ($item['quantity_approved'] ?? $item['quantity_requested']);
            if ((int) ($received[$item['id']] ?? $expected) !== $expected) {
                $matches = false;
            }
        }
        $status = $matches ? 'completed' : 'discrepancy';

        $this->db->transaction();
        try {
            $changed = $this->db->table($this->table)
                ->where('id', (int) $request['id'])
                ->in('status', ['in_transit', 'delivered'])
                ->update([
                    'status' => $status,
                    'received_by' => $userId,
                    'received_at' => date('Y-m-d H:i:s'),
                    'receipt_notes' => $notes !== '' ? $notes : null,
                ]);
            if (!$changed) {
                throw new DomainException('This request is not waiting for receipt.');
            }
            foreach ($items as $item) {
                $expected = (int) ($item['quantity_approved'] ?? $item['quantity_requested']);
                $this->db->table('inventory_request_items')->where('id', (int) $item['id'])
                    ->update(['quantity_received' => max(0, (int) ($received[$item['id']] ?? $expected))]);
            }
            $this->call->model('AuditModel');
            $this->AuditModel->record('inventory_request', (int) $request['id'], $status === 'completed' ? 'received' : 'discrepancy', $userId);
            $this->db->commit();
            return $status;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** The main office closes a discrepancy with its findings. */
    public function resolve($id, $notes, $userId)
    {
        $this->db->transaction();
        try {
            $changed = $this->db->table($this->table)
                ->where(['id' => (int) $id, 'status' => 'discrepancy'])
                ->update(['status' => 'completed', 'resolved_by' => $userId, 'resolved_at' => date('Y-m-d H:i:s'), 'resolution_notes' => $notes]);
            if ($changed) {
                $this->call->model('AuditModel');
                $this->AuditModel->record('inventory_request', (int) $id, 'resolved', $userId);
            }
            $this->db->commit();
            return $changed;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** [status => count] */
    public function status_counts($centerId = null)
    {
        $query = $this->db->table($this->table)->select('status')->select_count('id', 'total');
        if ($centerId) {
            $query->where('training_center_id', (int) $centerId);
        } else {
            $query->where('status', '!=', 'draft'); // the main office never sees a center's drafts
        }
        $rows = $query->group_by('status')->get_all();
        return array_map('intval', array_column($rows, 'total', 'status'));
    }

    /** REQ-0001, REQ-0002, ... (drafts carry a temporary DRAFT- code). */
    private function next_code()
    {
        $row = $this->db->raw(
            "SELECT MAX(CAST(SUBSTRING(request_code, 5) AS UNSIGNED)) AS n FROM inventory_requests WHERE request_code LIKE 'REQ-%'"
        )->fetch(PDO::FETCH_ASSOC);
        return sprintf('REQ-%04d', (int) ($row['n'] ?? 0) + 1);
    }

    /** DLV-2026-0001, numbered per year. */
    private function next_delivery_code()
    {
        $prefix = 'DLV-' . date('Y') . '-';
        $row = $this->db->raw(
            'SELECT MAX(CAST(SUBSTRING(delivery_code, ?) AS UNSIGNED)) AS n FROM inventory_deliveries WHERE delivery_code LIKE ?',
            [strlen($prefix) + 1, $prefix . '%']
        )->fetch(PDO::FETCH_ASSOC);
        return $prefix . str_pad((string) ((int) ($row['n'] ?? 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Adds items_summary, item_count and total_quantity to each request row. */
    private function attach_summaries(array $requests)
    {
        if (!$requests) {
            return $requests;
        }
        $ids = array_column($requests, 'id');
        $items = $this->db->table('inventory_request_items i')
            ->select('i.request_id, i.quantity_requested, i.quantity_approved, p.product_name, p.unit')
            ->inner_join('products p', 'p.id = i.product_id')
            ->in('i.request_id', $ids)
            ->order_by('p.product_name', 'ASC')
            ->get_all();
        $deliveries = $this->db->table('inventory_deliveries')
            ->select('request_id, delivery_code, status, prepared_at, released_at, delivered_at')
            ->in('request_id', $ids)
            ->where('status', '!=', 'cancelled')
            ->get_all();
        $deliveryBy = array_column($deliveries, null, 'request_id');

        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item['request_id']][] = $item;
        }
        foreach ($requests as &$request) {
            $list = $grouped[$request['id']] ?? [];
            $request['items_summary'] = implode(', ', array_map(fn ($i) => "{$i['product_name']} × {$i['quantity_requested']}", $list));
            $request['item_count'] = count($list);
            $request['total_quantity'] = array_sum(array_column($list, 'quantity_requested'));
            $request['delivery'] = $deliveryBy[$request['id']] ?? null;
        }
        return $requests;
    }
}
