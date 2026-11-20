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
        return $this->query()->order_by('product_name', 'ASC')->get_all();
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
