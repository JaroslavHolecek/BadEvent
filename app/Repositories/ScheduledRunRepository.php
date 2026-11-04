<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Remembers when periodic jobs triggered by page loads last ran (table scheduled_runs), so such a
 * job runs at most once per interval no matter which user, or how many at once, triggers it.
 */
final class ScheduledRunRepository
{
    public const DASHBOARD_CHECKS = 'dashboard_checks';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * Atomically claims $code if it never ran, or its last run is at least $intervalMinutes ago
     * (0 = always due, negative = never due). True only for the single call that stamped the new
     * run time; every other call within the interval — including simultaneous ones, the row lock
     * decides — gets false.
     */
    public function claimIfDue(string $code, int $intervalMinutes): bool
    {
        if ($intervalMinutes < 0) {
            return false;
        }
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO scheduled_runs (code, last_run_at)
            VALUES (:code, CURRENT_TIMESTAMP)
            ON CONFLICT (code) DO UPDATE
                SET last_run_at = EXCLUDED.last_run_at
                WHERE scheduled_runs.last_run_at <= EXCLUDED.last_run_at - make_interval(mins => CAST(:minutes AS integer))
            RETURNING code
        SQL);
        $stmt->execute([':code' => $code, ':minutes' => $intervalMinutes]);
        return $stmt->fetchColumn() !== false;
    }
}
