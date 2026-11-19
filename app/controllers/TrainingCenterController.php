<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

class TrainingCenterController extends SpaController
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role('admin', 'Administrator access is required.');
        $this->call->model('TrainingCenterModel');
    }

    public function index()
    {
        $this->json(['centers' => $this->TrainingCenterModel->all_with_counts()]);
    }

    public function create()
    {
        $input = $this->input();
        $center = [
            'center_code' => strtoupper($this->str($input, 'center_code')),
            'name' => $this->str($input, 'name'),
            'region' => $this->str($input, 'region'),
            'province' => $this->str($input, 'province'),
            'city' => $this->str($input, 'city'),
            'address' => $this->str($input, 'address')
        ];

        $errors = $this->validate_center($center);
        if ($errors) {
            $this->json_errors($errors);
        }

        $this->TrainingCenterModel->create(
            $center['center_code'],
            $center['name'],
            $center['region'],
            $center['province'],
            $center['city'],
            $center['address']
        );
        $this->json(['message' => 'Training center added.'], 201);
    }

    private function validate_center($center)
    {
        $errors = [];
        if (!preg_match('/^[A-Z0-9-]{2,40}$/', $center['center_code'])) {
            $errors[] = 'Center code must use 2 to 40 letters, numbers, or hyphens.';
        }
        if ($center['name'] === '' || strlen($center['name']) > 150) {
            $errors[] = 'Center name is required and must be at most 150 characters.';
        }
        if (strlen($center['region']) > 100 || strlen($center['province']) > 100 || strlen($center['city']) > 100) {
            $errors[] = 'Region, province, and city must each be at most 100 characters.';
        }
        if (strlen($center['address']) > 255) {
            $errors[] = 'Address must be at most 255 characters.';
        }

        $existing = $this->db->raw(
            'SELECT id FROM training_centers WHERE center_code = ? LIMIT 1',
            [$center['center_code']]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $errors[] = 'That training center code is already in use.';
        }
        return $errors;
    }
}
