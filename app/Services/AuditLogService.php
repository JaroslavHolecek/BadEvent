<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Persists significant application activity (auth events, entity CRUD, status
 * transitions, permission changes) into the audit_logs table for traceability.
 */
final class AuditLogService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function log(
        ?int $userId,
        string $entityType,
        int $entityId,
        string $operation,
        ?array $oldData = null,
        ?array $newData = null
    ): void {
        $sql = <<<SQL
            INSERT INTO audit_logs (user_id, entity_type, entity_id, operation, old_data, new_data)
            VALUES (:user_id, :entity_type, :entity_id, :operation, :old_data, :new_data)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id'     => $userId,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':operation'   => $operation,
            ':old_data'    => $oldData !== null ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null,
            ':new_data'    => $newData !== null ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
