<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Main office stock.
 * Everyone on the admin side can see the list (learning centers pick from it
 * when requesting supplies); only the main office can change it.
 */
class ProductController extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(['admin', 'center_admin', 'inventory_staff'], 'Inventory is for main office staff and learning center administrators.');
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
        $this->json([
            'product' => $this->product_or_404((int) $id),
            'movements' => $this->ProductModel->movements((int) $id),
            'reserved' => $this->ProductModel->reserved((int) $id),
        ]);
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
        $product = $this->product_or_404((int) $id);
        try {
            $this->ProductModel->delete((int) $id);
        } catch (PDOException $e) {
            // Products that appear on a supply request are kept for the record.
            $this->json_error('This item is part of a supply request, so it cannot be deleted.', 409);
        }
        $this->remove_image_file($product['image_path'] ?? null);
        $this->json(['message' => 'Item deleted.']);
    }

    /** GET /products/{id}/image: the item's photo, for both the main office and learning centers. */
    public function image($id)
    {
        $product = $this->product_or_404((int) $id);
        $path = $this->image_file($product['image_path'] ?? null);
        if ($path === null) {
            $this->json_error('This item has no photo.', 404);
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $this->io->set_status_code(200);
        $this->io->add_header([
            'Content-Type' => $mimeType,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            // The URL carries the file key, so a new photo gets a new URL and can be cached hard.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ], null);
        $this->io->send();
        readfile($path);
        exit;
    }

    /** POST /products/{id}/image (multipart "image"): adds or replaces the item's photo. */
    public function upload_image($id)
    {
        $this->require_role('admin', 'Only the main office can change item photos.');
        $product = $this->product_or_404((int) $id);
        $upload = $this->file('image');

        if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size']) || is_array($upload['error'])) {
            $this->json_error('Choose a photo of the item.', 422);
        }
        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $this->json_error('The photo did not upload completely. Try one smaller than 5 MB.', 422);
        }
        if (!is_uploaded_file($upload['tmp_name']) || (int) $upload['size'] < 1) {
            $this->json_error('The uploaded photo could not be verified.', 422);
        }
        if ((int) $upload['size'] > self::MAX_IMAGE_BYTES) {
            $this->json_error('Choose a photo smaller than 5 MB.', 422);
        }
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if (!isset(self::IMAGE_TYPES[$mimeType])) {
            $this->json_error('Use a JPEG, PNG or WebP photo.', 422);
        }
        $size = getimagesize($upload['tmp_name']);
        if ($size === false || $size[0] * $size[1] > 40000000) {
            $this->json_error('The photo is invalid or larger than 40 megapixels.', 422);
        }

        $directory = $this->image_directory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->json_error('The photo storage folder could not be created.', 500);
        }
        $key = bin2hex(random_bytes(16)) . self::IMAGE_TYPES[$mimeType];
        if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $key)) {
            $this->json_error('The photo could not be saved.', 500);
        }

        $this->ProductModel->update((int) $id, ['image_path' => $key]);
        $this->remove_image_file($product['image_path'] ?? null);
        $this->json(['message' => 'Photo saved.', 'product' => $this->ProductModel->find((int) $id)]);
    }

    /** DELETE /products/{id}/image */
    public function delete_image($id)
    {
        $this->require_role('admin', 'Only the main office can change item photos.');
        $product = $this->product_or_404((int) $id);
        $this->ProductModel->update((int) $id, ['image_path' => null]);
        $this->remove_image_file($product['image_path'] ?? null);
        $this->json(['message' => 'Photo removed.', 'product' => $this->ProductModel->find((int) $id)]);
    }

    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    private const IMAGE_TYPES = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];

    private function image_directory()
    {
        return config_item('storage_dir') . 'product-images';
    }

    /** Full path of a stored photo, or null when the key is empty, unsafe or the file is gone. */
    private function image_file($key)
    {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $key)) {
            return null;
        }
        $path = $this->image_directory() . DIRECTORY_SEPARATOR . $key;
        return is_file($path) ? $path : null;
    }

    private function remove_image_file($key)
    {
        $path = $this->image_file($key);
        if ($path !== null && !unlink($path)) {
            error_log('Could not remove an old product photo: ' . $key);
        }
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
