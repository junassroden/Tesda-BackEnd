<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * A small OpenTimestamps client (https://opentimestamps.org): anchors a
 * SHA-256 digest in the Bitcoin blockchain through public calendar servers,
 * for free and without a wallet.
 *
 *   submit()    sends the digest to calendars; each answers with a proof that
 *               ends in a "pending" attestation (the calendar's commitment)
 *   upgrade()   asks a calendar again later (hours) for the rest of the proof,
 *               which ends in a Bitcoin block header attestation
 *   bitcoin()   follows a proof to the Bitcoin attestation and returns the
 *               block height and the merkle root the proof commits to
 *
 * Only the 32-byte digest is sent; nothing else leaves the server.
 * Proofs use the OpenTimestamps binary timestamp format.
 */
class OpenTimestamps
{
    public const CALENDARS = [
        'https://a.pool.opentimestamps.org',
        'https://b.pool.opentimestamps.org',
        'https://a.pool.eternitywall.com',
    ];
    private const PENDING = "\x83\xdf\xe3\x0d\x2e\xf9\x0c\x8e";
    private const BITCOIN = "\x05\x88\x96\x0d\x73\xd7\x19\x01";

    /** Submits a 32-byte digest to every calendar. Returns [calendar => proof bytes] (failed calendars left out). */
    public static function submit($digest)
    {
        $proofs = [];
        foreach (self::CALENDARS as $calendar) {
            [$status, $body] = self::http('POST', $calendar . '/digest', $digest);
            if ($status === 200 && $body !== '') {
                $proofs[$calendar] = $body;
            }
        }
        return $proofs;
    }

    /**
     * Asks the calendar for the Bitcoin part of a pending proof.
     * Returns the upgrade proof bytes (starting at the commitment), or null when
     * the calendar has not reached Bitcoin yet.
     */
    public static function upgrade($calendar, $commitment)
    {
        [$status, $body] = self::http('GET', rtrim($calendar, '/') . '/timestamp/' . bin2hex($commitment));
        return $status === 200 && $body !== '' ? $body : null;
    }

    /** The commitment (message at the pending attestation) a calendar proof ends in, or null. */
    public static function commitment($proof, $digest)
    {
        foreach (self::leaves(self::parse($proof, $digest)) as $leaf) {
            if ($leaf['type'] === 'pending') {
                return $leaf['msg'];
            }
        }
        return null;
    }

    /** ['height' => int, 'merkle_root' => hex as shown by block explorers] from a proof, or null. */
    public static function bitcoin($proof, $msg)
    {
        foreach (self::leaves(self::parse($proof, $msg)) as $leaf) {
            if ($leaf['type'] === 'bitcoin') {
                // Bitcoin shows hashes byte-reversed.
                return ['height' => $leaf['height'], 'merkle_root' => bin2hex(strrev($leaf['msg']))];
            }
        }
        return null;
    }

    /** Parses a serialized timestamp into a tree. Throws RuntimeException on malformed input. */
    public static function parse($bytes, $msg)
    {
        $pos = 0;
        $tree = self::read_timestamp($bytes, $pos, $msg, 0);
        return $tree;
    }

    /** Every attestation in a tree, with the message it attests. */
    public static function leaves(array $node)
    {
        $out = [];
        foreach ($node['attestations'] as $a) {
            $out[] = $a + ['msg' => $node['msg']];
        }
        foreach ($node['children'] as $child) {
            $out = array_merge($out, self::leaves($child));
        }
        return $out;
    }

    /* ---------------------------------------------------------------- */

    private static function read_timestamp($bytes, &$pos, $msg, $depth)
    {
        if ($depth > 256) {
            throw new RuntimeException('Proof too deep.');
        }
        $node = ['msg' => $msg, 'attestations' => [], 'children' => []];
        $tag = self::byte($bytes, $pos);
        while ($tag === 0xff) {
            self::read_tag($bytes, $pos, self::byte($bytes, $pos), $node, $depth);
            $tag = self::byte($bytes, $pos);
        }
        self::read_tag($bytes, $pos, $tag, $node, $depth);
        return $node;
    }

    private static function read_tag($bytes, &$pos, $tag, array &$node, $depth)
    {
        if ($tag === 0x00) {
            $kind = self::take($bytes, $pos, 8);
            $payload = self::varbytes($bytes, $pos);
            if ($kind === self::PENDING) {
                $p = 0;
                $node['attestations'][] = ['type' => 'pending', 'uri' => self::varbytes($payload, $p)];
            } elseif ($kind === self::BITCOIN) {
                $p = 0;
                $node['attestations'][] = ['type' => 'bitcoin', 'height' => self::varuint($payload, $p)];
            } else {
                $node['attestations'][] = ['type' => 'unknown'];
            }
            return;
        }
        $msg = $node['msg'];
        switch ($tag) {
            case 0xf0: $result = $msg . self::varbytes($bytes, $pos); break;           // append
            case 0xf1: $result = self::varbytes($bytes, $pos) . $msg; break;           // prepend
            case 0x08: $result = hash('sha256', $msg, true); break;
            case 0x02: $result = hash('sha1', $msg, true); break;
            case 0x03: $result = hash('ripemd160', $msg, true); break;
            case 0x67: throw new RuntimeException('Keccak proofs are not supported.');
            case 0xf2: $result = strrev($msg); break;                                  // reverse
            case 0xf3: $result = bin2hex($msg); break;                                 // hexlify
            default: throw new RuntimeException(sprintf('Unknown proof operation 0x%02x.', $tag));
        }
        if (strlen($result) > 4096) {
            throw new RuntimeException('Proof message too long.');
        }
        $node['children'][] = self::read_timestamp($bytes, $pos, $result, $depth + 1);
    }

    private static function byte($bytes, &$pos)
    {
        if ($pos >= strlen($bytes)) {
            throw new RuntimeException('Proof ended early.');
        }
        return ord($bytes[$pos++]);
    }

    private static function take($bytes, &$pos, $n)
    {
        if ($n < 0 || $pos + $n > strlen($bytes)) {
            throw new RuntimeException('Proof ended early.');
        }
        $out = substr($bytes, $pos, $n);
        $pos += $n;
        return $out;
    }

    private static function varuint($bytes, &$pos)
    {
        $value = 0;
        $shift = 0;
        do {
            $b = self::byte($bytes, $pos);
            $value |= ($b & 0x7f) << $shift;
            $shift += 7;
            if ($shift > 56) {
                throw new RuntimeException('Number in proof too large.');
            }
        } while ($b & 0x80);
        return $value;
    }

    private static function varbytes($bytes, &$pos)
    {
        $len = self::varuint($bytes, $pos);
        if ($len > 8192) {
            throw new RuntimeException('Proof field too long.');
        }
        return self::take($bytes, $pos, $len);
    }

    /** [status, body]. Generic client name; only the digest is ever sent. */
    public static function http($method, $url, $body = null, $accept = 'application/vnd.opentimestamps.v1')
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'User-Agent: ots-php-client'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, $response === false ? '' : (string) $response];
    }
}
