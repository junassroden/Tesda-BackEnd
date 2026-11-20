<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/SpaController.php';

class Welcome extends SpaController
{
	public function index()
	{
		$this->json(['app' => 'TESDA NEXUS API', 'status' => 'ok']);
	}
}
