<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Anchors the blockchain ledger in Bitcoin with OpenTimestamps (blockchain_anchors).
 *
 *   anchor()   sends the newest block's hash to the OpenTimestamps calendars.
 *              Because every block contains the previous block's hash, this one
 *              anchor covers the whole chain up to that block.
 *   upgrade()  a few hours later, collects the Bitcoin part of each pending proof,
 *              checks it against the Bitcoin block's merkle root (public block
 *              explorer) and marks the anchor confirmed.
 *   verify()   re-checks a confirmed anchor from scratch: the block hash in the
 *              ledger today, the stored proof, and the Bitcoin block.
 *
 * Takes a query runner like BatchLifecycle and Ledger, so the admin page and
 * the CLI (php lava ledger:anchor) behave the same. Requires OpenTimestamps.
 */
class BlockchainAnchor
{
    private const EXPLORERS = ['https://blockstream.info/api', 'https://mempool.space/api'];

    /**
     * Anchors the newest block unless it is already anchored.
     * Returns ['anchored' => bool, 'anchor_id' => int|null, 'block_index' => int, 'message' => string].
     */
    public static function anchor(callable $query, $userId = null)
    {
        $head = $query('SELECT id, block_index, block_hash FROM blockchain_ledger ORDER BY block_index DESC LIMIT 1', [])->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            return ['anchored' => false, 'anchor_id' => null, 'block_index' => null, 'message' => 'The ledger is empty.'];
        }
        $existing = $query('SELECT id FROM blockchain_anchors WHERE ledger_id = ?', [$head['id']])->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return ['anchored' => false, 'anchor_id' => (int) $existing['id'], 'block_index' => (int) $head['block_index'],
                    'message' => "Block #{$head['block_index']} is already anchored. New blocks are anchored next time."];
        }
        $digest = hex2bin($head['block_hash']);
        $proofs = [];
        foreach (OpenTimestamps::submit($digest) as $calendar => $proof) {
            $commitment = OpenTimestamps::commitment($proof, $digest);
            if ($commitment !== null) {
                $proofs[] = ['calendar' => $calendar, 'proof' => base64_encode($proof), 'commitment' => bin2hex($commitment), 'upgrade' => null];
            }
        }
        if (!$proofs) {
            throw new RuntimeException('No OpenTimestamps calendar could be reached. Check the internet connection and try again.');
        }
        $query('INSERT INTO blockchain_anchors (ledger_id, block_index, block_hash, status, proofs, submitted_by, submitted_at)
                VALUES (?, ?, ?, \'pending\', ?, ?, ?)',
            [$head['id'], $head['block_index'], $head['block_hash'], json_encode($proofs), $userId ?: null, date('Y-m-d H:i:s')]);
        $id = (int) $query('SELECT id FROM blockchain_anchors WHERE ledger_id = ?', [$head['id']])->fetch(PDO::FETCH_ASSOC)['id'];
        $query('UPDATE blockchain_ledger SET network = ? WHERE id = ?', ['bitcoin-ots', $head['id']]);
        return ['anchored' => true, 'anchor_id' => $id, 'block_index' => (int) $head['block_index'],
                'message' => "Block #{$head['block_index']} was sent to " . count($proofs) . ' OpenTimestamps calendars. Bitcoin confirmation usually takes a few hours.'];
    }

    /** Collects Bitcoin proofs for pending anchors. Returns ['confirmed' => [ids], 'pending' => [ids]]. */
    public static function upgrade(callable $query)
    {
        $result = ['confirmed' => [], 'pending' => []];
        $pending = $query("SELECT id, ledger_id, block_hash, proofs FROM blockchain_anchors WHERE status = 'pending' ORDER BY id", [])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pending as $anchor) {
            $proofs = json_decode($anchor['proofs'], true) ?: [];
            $error = null;
            $confirmed = null;
            foreach ($proofs as &$p) {
                try {
                    if (!$p['upgrade']) {
                        $upgrade = OpenTimestamps::upgrade($p['calendar'], hex2bin($p['commitment']));
                        if ($upgrade !== null && OpenTimestamps::bitcoin($upgrade, hex2bin($p['commitment']))) {
                            $p['upgrade'] = base64_encode($upgrade);
                        }
                    }
                    if ($p['upgrade'] && !$confirmed) {
                        $btc = OpenTimestamps::bitcoin(base64_decode($p['upgrade']), hex2bin($p['commitment']));
                        $block = self::bitcoin_block($btc['height']);
                        if ($block && hash_equals($block['merkle_root'], $btc['merkle_root'])) {
                            $confirmed = $block + ['height' => $btc['height']];
                        } elseif ($block) {
                            $error = "The proof from {$p['calendar']} does not match Bitcoin block {$btc['height']}.";
                        }
                    }
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
            unset($p);
            $now = date('Y-m-d H:i:s');
            if ($confirmed) {
                $query("UPDATE blockchain_anchors SET status = 'confirmed', proofs = ?, bitcoin_height = ?, bitcoin_block = ?, bitcoin_time = ?,
                               merkle_root = ?, confirmed_at = ?, last_checked_at = ?, last_error = NULL WHERE id = ?",
                    [json_encode($proofs), $confirmed['height'], $confirmed['hash'], $confirmed['time'], $confirmed['merkle_root'], $now, $now, $anchor['id']]);
                $query('UPDATE blockchain_ledger SET network = ?, tx_hash = ? WHERE id = ?', ['bitcoin', $confirmed['hash'], $anchor['ledger_id']]);
                $result['confirmed'][] = (int) $anchor['id'];
            } else {
                $query('UPDATE blockchain_anchors SET proofs = ?, last_checked_at = ?, last_error = ? WHERE id = ?',
                    [json_encode($proofs), $now, $error ? mb_substr($error, 0, 500) : null, $anchor['id']]);
                $result['pending'][] = (int) $anchor['id'];
            }
        }
        return $result;
    }

    /**
     * Re-verifies one anchor from scratch. Returns ['valid' => bool|null, 'checks' => [...]]
     * (valid null while still waiting for Bitcoin).
     */
    public static function verify(callable $query, array $anchor)
    {
        $checks = [];
        $block = $query('SELECT block_hash FROM blockchain_ledger WHERE id = ?', [$anchor['ledger_id']])->fetch(PDO::FETCH_ASSOC);
        $same = $block && hash_equals($anchor['block_hash'], $block['block_hash']);
        $checks[] = ['label' => 'Anchored block is unchanged', 'ok' => $same,
                     'detail' => $same ? 'The block still has the hash that was anchored.' : 'The block hash in the ledger differs from the anchored one: the ledger was rewritten.'];
        $digest = hex2bin($anchor['block_hash']);
        $proven = null;
        foreach (json_decode($anchor['proofs'], true) ?: [] as $p) {
            try {
                $commitment = OpenTimestamps::commitment(base64_decode($p['proof']), $digest);
                if ($commitment === null || bin2hex($commitment) !== $p['commitment']) {
                    $checks[] = ['label' => "Proof from {$p['calendar']}", 'ok' => false, 'detail' => 'The stored proof does not lead from the block hash to the calendar commitment.'];
                    continue;
                }
                if (!$p['upgrade']) {
                    continue;
                }
                $btc = OpenTimestamps::bitcoin(base64_decode($p['upgrade']), $commitment);
                $bitcoinBlock = $btc ? self::bitcoin_block($btc['height']) : null;
                if (!$bitcoinBlock) {
                    $checks[] = ['label' => "Proof from {$p['calendar']}", 'ok' => null, 'detail' => 'The Bitcoin block explorer could not be reached.'];
                    continue;
                }
                $ok = hash_equals($bitcoinBlock['merkle_root'], $btc['merkle_root']);
                $checks[] = ['label' => "Bitcoin block {$btc['height']} ({$p['calendar']})", 'ok' => $ok,
                             'detail' => $ok ? "The proof leads from the block hash to the merkle root of Bitcoin block {$btc['height']}, mined " . $bitcoinBlock['time'] . ' UTC.'
                                             : 'The proof does not match the Bitcoin block.'];
                $proven = $proven || $ok;
            } catch (Throwable $e) {
                $checks[] = ['label' => "Proof from {$p['calendar']}", 'ok' => false, 'detail' => $e->getMessage()];
            }
        }
        if ($proven === null) {
            $checks[] = ['label' => 'Bitcoin confirmation', 'ok' => null, 'detail' => 'Waiting for the calendars to include the hash in a Bitcoin block (usually a few hours).'];
        }
        $failed = array_filter($checks, fn ($c) => $c['ok'] === false);
        return ['valid' => $failed ? false : ($proven ? true : null), 'checks' => $checks];
    }

    /** ['hash', 'merkle_root', 'time' (UTC)] of a Bitcoin block, from a public block explorer. */
    public static function bitcoin_block($height)
    {
        foreach (self::EXPLORERS as $api) {
            [$s1, $hash] = OpenTimestamps::http('GET', "{$api}/block-height/" . (int) $height, null, '*/*');
            $hash = trim($hash);
            if ($s1 !== 200 || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
                continue;
            }
            [$s2, $json] = OpenTimestamps::http('GET', "{$api}/block/{$hash}", null, 'application/json');
            $block = $s2 === 200 ? json_decode($json, true) : null;
            if (!empty($block['merkle_root'])) {
                return ['hash' => $hash, 'merkle_root' => $block['merkle_root'], 'time' => gmdate('Y-m-d H:i:s', (int) $block['timestamp'])];
            }
        }
        return null;
    }
}
