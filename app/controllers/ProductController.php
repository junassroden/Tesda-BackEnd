<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

/**
 * Main office stock.
 * Everyone on the admin side can see the list (learning centers pick from it
 * when requesting supplies); only the main office can change it.
 */
class ProductController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(['admin', 'center_admin'], 'Inventory is for main office and learning center administrators.');
        $this->call->model('ProductModel');
    }

    /** GET /products */
    public function index()
    {
        $this->json(['products' => $this->ProductModel->listing()]);
    }

    /** GET /products/{id} */
    public function show($id)
    {
        $this->require_role('admin', 'Only the main office can edit stock.');
        $this->json(['product' => $this->product_or_404((int) $id)]);
    }

    /** POST /products  { product_name, description, quantity, price } */
    public function create()
    {
        $this->require_role('admin', 'Only the main office can add stock.');
        $product = $this->validated_product(0);
        $product['created_by'] = $this->user_id();
        $id = $this->ProductModel->insert($product);
        $this->json(['message' => 'Item added.', 'product' => $this->ProductModel->find($id)], 201);
    }

    /** PUT /products/{id} */
    public function update($id)
    {
        $this->require_role('admin', 'Only the main office can edit stock.');
        $this->product_or_404((int) $id);
        $this->ProductModel->update((int) $id, $this->validated_product((int) $id));
        $this->json(['message' => 'Item updated.', 'product' => $this->ProductModel->find((int) $id)]);
    }

    /** DELETE /products/{id} */
    public function delete($id)
    {
        $this->require_role('admin', 'Only the main office can remove stock.');
        $this->product_or_404((int) $id);
        try {
            $this->ProductModel->delete((int) $id);
        } catch (PDOException $e) {
            // Products that appear on a supply request are kept for the record.
            $this->json_error('This item is part of a supply request, so it cannot be deleted.', 409);
        }
        $this->json(['message' => 'Item deleted.']);
    }

    private function validated_product($exceptId)
    {
        $this->validate([
            'product_name|Item name' => 'required|max_length[100]',
            'description|Description' => 'max_length[255]',
            'quantity|Quantity' => 'numeric|greater_than_equal_to[0]',
            'price|Price' => 'numeric|greater_than_equal_to[0]',
        ]);
        $input = $this->input();
        $name = $this->str($input, 'product_name');
        if (!preg_match('/^[\p{L}0-9 .,()\/&+#\'-]+$/u', $name)) {
            $this->json_error('Item name can use letters, numbers, spaces, and . , ( ) / & + # \' -', 422);
        }
        if ($this->ProductModel->name_taken($name, $exceptId)) {
            $this->json_error('An item with that name already exists.', 422);
        }
        return [
            'product_name' => $name,
            'description' => $this->str($input, 'description'),
            'quantity' => (int) ($this->str($input, 'quantity') ?: 0),
            'price' => round((float) ($this->str($input, 'price') ?: 0), 2),
        ];
    }

    private function product_or_404($id)
    {
        $product = $this->ProductModel->find($id);
        if (!$product) {
            $this->json_error('Item not found.', 404);
        }
        return $product;
    }
}
