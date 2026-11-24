<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** products: supplies and equipment held by the main office. */
class ProductModel extends Model
{
    protected $table = 'products';
    protected $primary_key = 'id';
    protected $fillable = [
        'product_name', 'description', 'category', 'unit', 'price',
        'quantity', 'reorder_level', 'image_path', 'created_by',
    ];

    public function listing()
    {
        return $this->db->table($this->table)
            ->order_by('product_name', 'ASC')
            ->get_all();
    }

    /** Latest stock movements for an item, with the center, request and who recorded them. */
    public function movements($id, $limit = 8)
    {
        return $this->db->table('stock_movements m')
            ->select('m.id, m.movement_type, m.quantity_change, m.remarks, m.created_at,
                      c.name AS center_name, r.request_code, d.delivery_code, u.full_name AS recorded_by_name')
            ->left_join('training_centers c', 'c.id = m.training_center_id')
            ->left_join('inventory_requests r', 'r.id = m.request_id')
            ->left_join('inventory_deliveries d', 'd.id = m.delivery_id')
            ->left_join('users u', 'u.id = m.recorded_by')
            ->where('m.product_id', (int) $id)
            ->order_by('m.created_at', 'DESC')
            ->limit(max(1, (int) $limit))
            ->get_all();
    }

    /** Units asked for by requests that are still open (submitted, not yet released, disapproved or cancelled). */
    public function reserved($id)
    {
        $rows = $this->db->table('inventory_request_items i')
            ->select('i.request_id, i.quantity_requested, i.quantity_approved')
            ->inner_join('inventory_requests r', 'r.id = i.request_id')
            ->where('i.product_id', (int) $id)
            ->in('r.status', ['pending', 'approved', 'processing', 'ready_for_delivery'])
            ->get_all();

        $units = 0;
        foreach ($rows as $row) {
            $units += (int) ($row['quantity_approved'] ?? $row['quantity_requested']);
        }
        return [
            'units' => $units,
            'requests' => count(array_unique(array_column($rows, 'request_id'))),
        ];
    }

    public function name_taken($name, $exceptId = 0)
    {
        return (bool) $this->db->table($this->table)
            ->where('product_name', $name)
            ->where('id', '!=', $exceptId)
            ->get();
    }

    /**
     * Take items out of stock only if enough are left. The check and the
     * update are one statement, so two releases can't overdraw stock.
     * Returns true when the stock was taken.
     */
    public function take_stock($productId, $quantity)
    {
        $affected = $this->db->table($this->table)
            ->where('id', $productId)
            ->where('quantity', '>=', $quantity)
            ->decrement('quantity', $quantity);
        return $affected > 0;
    }
}
