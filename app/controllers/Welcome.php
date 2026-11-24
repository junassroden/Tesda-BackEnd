<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Welcome extends MY_Controller
{
	public function index()
	{
		$this->json(['app' => 'TESDA NEXUS API', 'status' => 'ok']);
	}
}
