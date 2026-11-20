<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** inventory_requests (+ inventory_request_items, stock_movements): supply requests from learning centers. */
class InventoryRequestModel extends Model
{
    protected $table = 'inventory_requests';
    protected $primary_key = 'id';
    protected $fillable = [
        'request_code', 'training_center_id', 'requested_by', 'delivery_personnel_id', 'purpose', 'status',
        'admin_remarks', 'reviewed_by', 'reviewed_at', 'released_at', 'received_at',
    ];

    /** All requests (or one center's), newest first, with an items summary. */
    public function listing($centerId = null, $limit = null)
    {
        $query = $this->db->table('inventory_requests r')
            ->select('r.id, r.request_code, r.status, r.purpose, r.created_at, r.reviewed_at, r.released_at, r.received_at,
                      r.training_center_id, tc.name AS center_name, u.full_name AS requested_by_name')
            ->inner_join('training_centers tc', 'tc.id = r.training_center_id')
            ->left_join('users u', 'u.id = r.requested_by');
        if ($centerId) {
            $query->where('r.training_center_id', (int) $centerId);
        }
        $query->order_by('r.id', 'DESC');
        if ($limit) {
            $query->limit((int) $limit);
        }
        return $this->attach_item_summaries($query->get_all());
    }

    /** One request with center, requester, reviewer, and delivery person. */
    public function detail($id)
    {
        return $this->db->table('inventory_requests r')
            ->select('r.id, r.request_code, r.training_center_id, r.requested_by, r.delivery_personnel_id, r.purpose,
                      r.status, r.admin_remarks, r.reviewed_by, r.reviewed_at, r.released_at, r.received_at, r.created_at,
                      tc.name AS center_name, u.full_name AS requested_by_name, rv.full_name AS reviewed_by_name,
                      d.full_name AS delivery_name, d.contact_number AS delivery_contact,
                      d.vehicle AS delivery_vehicle, d.plate_number AS delivery_plate')
            ->inner_join('training_centers tc', 'tc.id = r.training_center_id')
            ->left_join('users u', 'u.id = r.requested_by')
            ->left_join('users rv', 'rv.id = r.reviewed_by')
            ->left_join('delivery_personnel d', 'd.id = r.delivery_personnel_id')
            ->where('r.id', (int) $id)
            ->get();
    }

    public function items($requestId)
    {
        return $this->db->table('inventory_request_items i')
            ->select('i.id, i.product_id, i.quantity_requested, i.quantity_approved, p.product_name, p.unit, p.quantity AS stock')
            ->inner_join('products p', 'p.id = i.product_id')
            ->where('i.request_id', (int) $requestId)
            ->order_by('p.product_name', 'ASC')
            ->get_all();
    }

    /** items: [['product_id' => int, 'quantity' => int], ...]. Returns the new request id. */
    public function submit($centerId, $userId, $deliveryId, $purpose, array $items)
    {
        $this->db->transaction();
        try {
            $id = $this->insert([
                'request_code' => $this->next_code(),
                'training_center_id' => $centerId,
                'requested_by' => $userId,
                'delivery_personnel_id' => $deliveryId ?: null,
                'purpose' => $purpose,
                'status' => 'pending',
            ]);
            foreach ($items as $item) {
                $this->db->table('inventory_request_items')->insert([
                    'request_id' => $id,
                    'product_id' => $item['product_id'],
                    'quantity_requested' => $item['quantity'],
                ]);
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    /** in_review / approved / rejected with the admin's note. Approving approves every requested quantity. */
    public function review($id, $status, $remarks, $reviewerId)
    {
        $this->update($id, [
            'status' => $status,
            'admin_remarks' => $remarks !== '' ? $remarks : null,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        if ($status === 'approved') {
            foreach ($this->items($id) as $item) {
                $this->db->table('inventory_request_items')
                    ->where('id', $item['id'])
                    ->update(['quantity_approved' => $item['quantity_requested']]);
            }
        }
    }

    /**
     * Release an approved request: take the items out of stock, record the stock
     * movements, mark it released, and chain it on the ledger. All or nothing.
     */
    public function release($id, $userId, ProductModel $products, AuditModel $audit)
    {
        $request = $this->find($id);
        $items = $this->items($id);

        $this->db->transaction();
        try {
            foreach ($items as $item) {
                $qty = (int) ($item['quantity_approved'] ?? $item['quantity_requested']);
                if (!$products->take_stock($item['product_id'], $qty)) {
                    throw new RuntimeException("Not enough {$item['product_name']} in stock ({$item['stock']} left).");
                }
                $this->db->table('stock_movements')->insert([
                    'product_id' => $item['product_id'],
                    'movement_type' => 'release',
                    'quantity_change' => -1 * $qty,
                    'request_id' => $id,
                    'training_center_id' => $request['training_center_id'],
                    'remarks' => 'Released for ' . $request['request_code'],
                    'recorded_by' => $userId,
                ]);
            }
            $this->update($id, ['status' => 'released', 'released_at' => date('Y-m-d H:i:s')]);

            $audit->chain('inventory_request', $id, 'released', [
                'request_code' => $request['request_code'],
                'training_center_id' => (int) $request['training_center_id'],
                'items' => array_map(fn ($i) => [
                    'product_id' => (int) $i['product_id'],
                    'quantity' => (int) ($i['quantity_approved'] ?? $i['quantity_requested']),
                ], $items),
            ], $userId);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function mark_received($id)
    {
        return $this->update($id, ['status' => 'received', 'received_at' => date('Y-m-d H:i:s')]);
    }

    /** [status => count] */
    public function status_counts($centerId = null)
    {
        $query = $this->db->table($this->table)->select('status')->select_count('id', 'total');
        if ($centerId) {
            $query->where('training_center_id', (int) $centerId);
        }
        $rows = $query->group_by('status')->get_all();
        return array_map('intval', array_column($rows, 'total', 'status'));
    }

    /** REQ-0001, REQ-0002, ... */
    private function next_code()
    {
        $row = $this->db->table($this->table)->select_max('id', 'last_id')->get();
        return sprintf('REQ-%04d', (int) ($row['last_id'] ?? 0) + 1);
    }

    /** Adds items_summary ("Safety goggles × 4, ...") and total_quantity to each request row. */
    private function attach_item_summaries(array $requests)
    {
        if (!$requests) {
            return $requests;
        }
        $items = $this->db->table('inventory_request_items i')
            ->select('i.request_id, i.quantity_requested, p.product_name')
            ->inner_join('products p', 'p.id = i.product_id')
            ->in('i.request_id', array_column($requests, 'id'))
            ->order_by('p.product_name', 'ASC')
            ->get_all();

        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item['request_id']][] = $item;
        }
        foreach ($requests as &$request) {
            $list = $grouped[$request['id']] ?? [];
            $request['items_summary'] = implode(', ', array_map(fn ($i) => "{$i['product_name']} × {$i['quantity_requested']}", $list));
            $request['total_quantity'] = array_sum(array_column($list, 'quantity_requested'));
        }
        return $requests;
    }
}
