<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Automatic batch status changes. Runs from the API (whenever batch lists load)
 * and from the CLI (php lava batches:lifecycle, for Windows Task Scheduler or cron),
 * so it takes a plain query runner instead of a framework model:
 *   $query(string $sql, array $params): PDOStatement
 *
 * Rules:
 *   upcoming  -> ongoing    on or after the start date.
 *   ongoing   -> completed  only when ALL of these hold:
 *                  - the end date has passed (extensions move it),
 *                  - the batch does not require an administrator's confirmation,
 *                  - recorded training hours (held sessions) reach the required
 *                    hours (the batch's total, else the course's).
 *                Batches past their end date that fail any rule stay ongoing and
 *                are reported as "needs review". Suspended batches are never touched.
 *
 * Completing a batch completes its active trainees (batch_students) and their
 * course enrollments. Each change is written to activity_logs (no user: system).
 */
class BatchLifecycle
{
    /** Returns ['started' => [ids], 'completed' => [ids]]. */
    public static function run(callable $query, $today = null)
    {
        $today = $today ?: date('Y-m-d');
        $result = ['started' => [], 'completed' => []];

        $starting = $query(
            "SELECT b.id, b.batch_name, c.training_center_id
               FROM batches b INNER JOIN courses c ON c.id = b.course_id
              WHERE b.status = 'upcoming' AND b.start_date IS NOT NULL AND b.start_date <= ?",
            [$today]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($starting as $batch) {
            $changed = $query("UPDATE batches SET status = 'ongoing' WHERE id = ? AND status = 'upcoming'", [$batch['id']])->rowCount();
            if ($changed) {
                self::log($query, $batch, 'started', "{$batch['batch_name']} started on its start date.");
                $result['started'][] = (int) $batch['id'];
            }
        }

        $due = $query(
            "SELECT b.id, b.batch_name, b.course_id, c.training_center_id
               FROM batches b
              INNER JOIN courses c ON c.id = b.course_id
               LEFT JOIN (SELECT batch_id, SUM(hours) AS held_hours FROM batch_sessions
                           WHERE status = 'held' GROUP BY batch_id) s ON s.batch_id = b.id
              WHERE b.status = 'ongoing'
                AND b.end_date IS NOT NULL AND b.end_date < ?
                AND b.requires_confirmation = 0
                AND COALESCE(b.total_hours, c.duration_hours) IS NOT NULL
                AND COALESCE(s.held_hours, 0) >= COALESCE(b.total_hours, c.duration_hours)",
            [$today]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($due as $batch) {
            if (self::complete($query, $batch, null, 'auto')) {
                self::log($query, $batch, 'completed', "{$batch['batch_name']} completed automatically: end date passed and required training hours were recorded.");
                $result['completed'][] = (int) $batch['id'];
            }
        }
        return $result;
    }

    /**
     * Marks a batch completed with its active trainees and their course
     * enrollments. Returns false when the batch was no longer ongoing/suspended.
     * Call inside a transaction when other writes depend on it.
     */
    public static function complete(callable $query, array $batch, $userId, $mode)
    {
        $changed = $query(
            "UPDATE batches SET status = 'completed', completed_at = NOW(), completed_by = ?, completion_mode = ?,
                    suspended_at = NULL
              WHERE id = ? AND status IN ('ongoing', 'suspended')",
            [$userId ?: null, $mode, $batch['id']]
        )->rowCount();
        if (!$changed) {
            return false;
        }
        $query(
            "UPDATE enrollments e
              INNER JOIN batch_students bs ON bs.student_id = e.student_id AND bs.batch_id = ? AND bs.status = 'active'
                SET e.status = 'completed'
              WHERE e.course_id = ? AND e.status = 'enrolled'",
            [$batch['id'], $batch['course_id']]
        );
        $query("UPDATE batch_students SET status = 'completed', ended_at = NOW() WHERE batch_id = ? AND status = 'active'", [$batch['id']]);
        return true;
    }

    private static function log(callable $query, array $batch, $action, $description)
    {
        $query(
            "INSERT INTO activity_logs (user_id, training_center_id, action, entity_type, entity_id, description, ip_address)
             VALUES (NULL, ?, ?, 'batch', ?, ?, NULL)",
            [$batch['training_center_id'], $action, $batch['id'], mb_substr($description, 0, 500)]
        );
    }
}
