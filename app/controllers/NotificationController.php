<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** The signed-in user's notifications (top bar bell). */
class NotificationController extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_login();
        $this->call->model('NotificationModel');
    }

    /** GET /notifications */
    public function index()
    {
        $this->json([
            'notifications' => $this->NotificationModel->for_user($this->user_id()),
            'unread' => $this->NotificationModel->unread_count($this->user_id()),
        ]);
    }

    /** POST /notifications/read  { id } (no id = all) */
    public function read()
    {
        $id = (int) ($this->input()['id'] ?? 0);
        $this->NotificationModel->mark_read($this->user_id(), $id ?: null);
        $this->json(['unread' => $this->NotificationModel->unread_count($this->user_id())]);
    }
}
