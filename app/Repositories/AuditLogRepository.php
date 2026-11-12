<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/** Read side of the audit_logs table (written by App\Services\AuditLogService) — used by the TechAdmin logs page. */
final class AuditLogRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * Log rows created between $dateFrom and $dateTo (both inclusive, 'Y-m-d', null = unbounded),
     * newest first, with the acting user's name/email. Fetches $limit + 1 rows so the caller can
     * tell whether the result was truncated.
     */
    public function listByDateRange(?string $dateFrom, ?string $dateTo, int $limit): array
    {
        $sql = <<<SQL
            SELECT l.id, l.created_at, l.user_id, l.entity_type, l.entity_id, l.operation,
                   l.old_data, l.new_data,
                   p.full_name, bl.email
            FROM audit_logs l
            LEFT JOIN app_users au ON au.id = l.user_id
            LEFT JOIN persons p    ON p.id = au.person_id
            LEFT JOIN bl_login bl  ON bl.id = l.user_id
            WHERE TRUE
        SQL;
        $bind = [];
        if ($dateFrom !== null) {
            $sql .= " AND l.created_at >= CAST(:date_from AS DATE)";
            $bind[':date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $sql .= " AND l.created_at < CAST(:date_to AS DATE) + INTERVAL '1 day'";
            $bind[':date_to'] = $dateTo;
        }
        $sql .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($bind as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
