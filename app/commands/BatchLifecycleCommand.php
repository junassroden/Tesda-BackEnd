<?php

/**
 * php lava batches:lifecycle [--date=YYYY-MM-DD]
 *
 * Starts batches that reached their start date and completes batches whose
 * end date passed with their required training hours recorded (see
 * app/libraries/BatchLifecycle.php). Schedule it once a day, e.g. with
 * Windows Task Scheduler:
 *   Program:   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
 *   Arguments: lava batches:lifecycle
 *   Start in:  C:\laragon\www\TesdaNexus\Back-End
 * The API also runs the same check whenever batch lists load, so a missed
 * run only delays a change until someone opens the portal.
 */
class BatchLifecycleCommand
{
    public static $command = 'batches:lifecycle';

    public static $description = 'Start and complete training batches by their schedule and recorded hours';

    public static $arguments = [
        '[--date=<YYYY-MM-DD>]' => 'Evaluate as of this date (default: today, Asia/Manila)',
    ];

    public function handle($input = null, array $flags = [])
    {
        if (!defined('PREVENT_DIRECT_ACCESS')) {
            define('PREVENT_DIRECT_ACCESS', true);
        }
        require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'BatchLifecycle.php';
        date_default_timezone_set('Asia/Manila');

        $date = $flags['date'] ?? null;
        if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            echo "Use --date=YYYY-MM-DD\n";
            exit(1);
        }

        $env = self::env(dirname(APP_DIR) . DIRECTORY_SEPARATOR . '.env');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_NAME'] ?? ($env['DB_DATABASE'] ?? ''));
        try {
            $pdo = new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '+08:00'");
            $query = function ($sql, $params = []) use ($pdo) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            };

            $pdo->beginTransaction();
            $result = BatchLifecycle::run($query, $date);
            $pdo->commit();
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(STDERR, 'Batch lifecycle failed: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }

        printf("Started: %d batch(es)%s\n", count($result['started']), $result['started'] ? ' (' . implode(', ', $result['started']) . ')' : '');
        printf("Completed: %d batch(es)%s\n", count($result['completed']), $result['completed'] ? ' (' . implode(', ', $result['completed']) . ')' : '');
    }

    /** Minimal .env reader (KEY=value lines; # comments). */
    private static function env($file)
    {
        $values = [];
        if (!is_file($file)) {
            return $values;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $values[$key] = trim($value, "\"'");
        }
        return $values;
    }
}
