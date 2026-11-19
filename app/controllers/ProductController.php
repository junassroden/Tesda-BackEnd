<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

class ProductController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_login();
        $this->call->model('ProductModel');
    }

    public function index()
    {
        $this->json(['products' => $this->ProductModel->read()]);
    }

    public function show($id)
    {
        $this->require_admin();
        $product = $this->ProductModel->find((int) $id);
        if (!$product) {
            $this->json_error('Item not found.', 404);
        }
        $this->json(['product' => $product]);
    }

    public function create()
    {
        $this->require_admin();
        $product = $this->product_input();
        $this->ProductModel->create($product['product_name'], $product['description'], $product['price'], $product['quantity']);
        $this->json(['message' => 'Product added successfully.'], 201);
    }

    public function update($id)
    {
        $this->require_admin();
        if (!$this->ProductModel->find((int) $id)) {
            $this->json_error('Item not found.', 404);
        }
        $product = $this->product_input();
        $this->ProductModel->update((int) $id, $product['product_name'], $product['description'], $product['price'], $product['quantity']);
        $this->json(['message' => 'Product updated successfully.']);
    }

    public function delete($id)
    {
        $this->require_admin();
        $this->ProductModel->delete((int) $id);
        $this->json(['message' => 'Product deleted successfully.']);
    }

    /** Same rules as the old form: required, alpha-numeric name, 255-char description, numeric price and quantity. */
    private function product_input()
    {
        $input = $this->input();
        $product = [
            'product_name' => $this->str($input, 'product_name'),
            'description' => $this->str($input, 'description'),
            'price' => $this->str($input, 'price'),
            'quantity' => $this->str($input, 'quantity')
        ];
        $errors = [];
        if ($product['product_name'] === '' || !preg_match('/^[a-z0-9 ]+$/i', $product['product_name'])) {
            $errors[] = 'Item name is required and may contain only letters, numbers, and spaces.';
        }
        if ($product['description'] === '' || mb_strlen($product['description']) > 255) {
            $errors[] = 'Description is required and must be at most 255 characters.';
        }
        if (!is_numeric($product['price'])) {
            $errors[] = 'Price must be a number.';
        }
        if (!is_numeric($product['quantity'])) {
            $errors[] = 'Quantity must be a number.';
        }
        if ($errors) {
            $this->json_errors($errors);
        }
        return $product;
    }

    private function require_admin()
    {
        $this->require_role('admin', 'Administrator access is required for this action.');
    }
}
