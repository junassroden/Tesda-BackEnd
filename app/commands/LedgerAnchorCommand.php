<?php

/**
 * php lava ledger:anchor [--check-only]
 *
 * Collects Bitcoin confirmations for pending anchors, then anchors the newest
 * ledger block in Bitcoin through OpenTimestamps if it is not anchored yet
 * (see app/libraries/BlockchainAnchor.php). Schedule it every few hours with
 * Windows Task Scheduler or cron:
 *   Program:   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
 *   Arguments: lava ledger:anchor
 *   Start in:  C:\laragon\www\TesdaNexus\Back-End
 * Runs outside LavaLust's request cycle, so it loads its libraries itself.
 */
class LedgerAnchorCommand
{
    public static $command = 'ledger:anchor';

    public static $description = 'Anchor the blockchain ledger in Bitcoin (OpenTimestamps) and collect confirmations';

    public static $arguments = [
        '[--check-only]' => 'Only collect confirmations; do not anchor a new block',
    ];

    public function handle($input = null, array $flags = [])
    {
        if (!defined('PREVENT_DIRECT_ACCESS')) {
            define('PREVENT_DIRECT_ACCESS', true);
        }
        foreach (['OpenTimestamps', 'BlockchainAnchor'] as $lib) {
            require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . $lib . '.php';
        }
        date_default_timezone_set('Asia/Manila');

        $env = [];
        $file = dirname(APP_DIR) . DIRECTORY_SEPARATOR . '.env';
        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#' && strpos($line, '=') !== false) {
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                $env[$k] = trim($v, "\"'");
            }
        }
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_NAME'] ?? ''),
                $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
            $pdo->exec("SET time_zone = '+08:00'");
            $query = function ($sql, $params = []) use ($pdo) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            };

            $up = BlockchainAnchor::upgrade($query);
            printf("Confirmed in Bitcoin: %d, still pending: %d\n", count($up['confirmed']), count($up['pending']));
            if (empty($flags['check-only'])) {
                $result = BlockchainAnchor::anchor($query, null);
                echo $result['message'], PHP_EOL;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'Ledger anchoring failed: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }
}
