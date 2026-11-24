<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Blockchain ledger (main office administrators): browse blocks, verify the
 * whole chain, and verify single blocks against today's records and files.
 */
class LedgerController extends MY_Controller
{
    private const TYPES = ['system', 'document', 'student_document', 'inventory_request', 'inventory_delivery', 'allowance_request', 'batch', 'seal'];

    public function __construct()
    {
        parent::__construct();
        $this->require_role('admin', 'The blockchain ledger is for main office administrators.');
        $this->call->model('LedgerModel');
        $this->call->model('AuditModel');
    }

    /** GET /ledger?page=&entity_type=&entity_id=&q= */
    public function index()
    {
        $type = (string) ($_GET['entity_type'] ?? '');
        $q = preg_replace('/[^a-f0-9]/i', '', (string) ($_GET['q'] ?? ''));
        [$blocks, $total] = $this->LedgerModel->listing([
            'entity_type' => in_array($type, self::TYPES, true) ? $type : '',
            'entity_id' => (int) ($_GET['entity_id'] ?? 0),
            'q' => substr($q, 0, 64),
        ], max(1, (int) ($_GET['page'] ?? 1)), 25);
        $this->json(['blocks' => $blocks, 'total' => $total, 'per_page' => 25, 'stats' => $this->LedgerModel->stats(),
            'anchored' => $this->LedgerModel->anchored_upto()]);
    }

    /** GET /ledger/{id} */
    public function show($id)
    {
        $block = $this->block_or_404((int) $id);
        $history = $this->db->table('blockchain_verifications v')
            ->select('v.id, v.recomputed_hash, v.is_valid, v.remarks, v.verified_at, u.full_name AS verified_by_name')
            ->left_join('users u', 'u.id = v.verified_by')
            ->where('v.ledger_id', (int) $block['id'])
            ->order_by('v.id', 'DESC')
            ->limit(10)
            ->get_all();
        $this->json(['block' => $block, 'verifications' => $history]);
    }

    /** POST /ledger/verify : walks the whole chain. */
    public function verify_chain()
    {
        $result = $this->LedgerModel->verify_chain();
        $this->AuditModel->log($this->user_id(), null, 'verified', 'blockchain', null,
            $result['valid'] ? "Verified the whole ledger: {$result['blocks_checked']} blocks intact" : "Ledger verification failed at block #{$result['first_broken']['block_index']}: {$result['reason']}");
        $this->json($result);
    }

    /** POST /ledger/{id}/verify : one block against its records and files; saved in blockchain_verifications. */
    public function verify_block($id)
    {
        $block = $this->block_or_404((int) $id);
        $result = $this->LedgerModel->verify_block($block, $this->user_id());
        $this->json(array_merge(['block' => $this->LedgerModel->find_block($block['id'])], $result));
    }

    /** GET /ledger/anchors : public blockchain anchors (OpenTimestamps / Bitcoin). */
    public function anchors()
    {
        $this->json(['anchors' => $this->LedgerModel->anchors(), 'anchored' => $this->LedgerModel->anchored_upto()]);
    }

    /** POST /ledger/anchors : anchor the newest block now (also done by php lava ledger:anchor). */
    public function anchor()
    {
        $this->load_anchoring();
        try {
            $result = BlockchainAnchor::anchor(fn ($sql, $params = []) => $this->db->raw($sql, $params), $this->user_id());
        } catch (RuntimeException $e) {
            $this->json_error($e->getMessage(), 502);
        }
        if ($result['anchored']) {
            $this->AuditModel->log($this->user_id(), null, 'anchored', 'blockchain', $result['anchor_id'],
                "Sent block #{$result['block_index']} to OpenTimestamps for anchoring in Bitcoin");
        }
        $this->json(['message' => $result['message'], 'anchors' => $this->LedgerModel->anchors(), 'anchored' => $this->LedgerModel->anchored_upto()]);
    }

    /** POST /ledger/anchors/check : collect Bitcoin confirmations for pending anchors. */
    public function check_anchors()
    {
        $this->load_anchoring();
        $result = BlockchainAnchor::upgrade(fn ($sql, $params = []) => $this->db->raw($sql, $params));
        $message = $result['confirmed']
            ? count($result['confirmed']) . ' anchor(s) confirmed in Bitcoin.'
            : ($result['pending'] ? 'Still waiting for Bitcoin. Calendars usually confirm within a few hours.' : 'No anchor is waiting for confirmation.');
        $this->json(['message' => $message, 'anchors' => $this->LedgerModel->anchors(), 'anchored' => $this->LedgerModel->anchored_upto()]);
    }

    /** POST /ledger/anchors/{id}/verify : re-check an anchor against the ledger and Bitcoin. */
    public function verify_anchor($id)
    {
        $anchor = $this->LedgerModel->find_anchor((int) $id);
        if (!$anchor) {
            $this->json_error('Anchor not found.', 404);
        }
        $this->load_anchoring();
        $this->json(BlockchainAnchor::verify(fn ($sql, $params = []) => $this->db->raw($sql, $params), $anchor));
    }

    private function load_anchoring()
    {
        $this->call->library('OpenTimestamps');
        $this->call->library('BlockchainAnchor');
    }

    private function block_or_404($id)
    {
        $block = $this->LedgerModel->find_block($id);
        if (!$block) {
            $this->json_error('Block not found.', 404);
        }
        return $block;
    }
}
