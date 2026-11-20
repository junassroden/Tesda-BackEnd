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

    /** GET /centers */
    public function index()
    {
        $this->json(['centers' => $this->TrainingCenterModel->all_with_counts()]);
    }

    /** POST /centers  { center_code, name, region, province, city, address } */
    public function create()
    {
        $this->validate([
            'center_code|Center code' => 'required|min_length[2]|max_length[40]|alpha_numeric_dash',
            'name|Center name' => 'required|max_length[150]',
            'region|Region' => 'max_length[100]',
            'province|Province' => 'max_length[100]',
            'city|City' => 'max_length[100]',
            'address|Address' => 'max_length[255]',
        ]);
        $input = $this->input();
        $code = strtoupper($this->str($input, 'center_code'));
        if ($this->TrainingCenterModel->code_taken($code)) {
            $this->json_error('That training center code is already in use.', 422);
        }

        $this->TrainingCenterModel->insert([
            'center_code' => $code,
            'name' => $this->str($input, 'name'),
            'region' => $this->str($input, 'region'),
            'province' => $this->str($input, 'province'),
            'city' => $this->str($input, 'city'),
            'address' => $this->str($input, 'address'),
            'is_active' => 1,
        ]);
        $this->json(['message' => 'Training center added.'], 201);
    }
}
