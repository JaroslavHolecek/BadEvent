<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class RoleRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function listAll(): array
    {
        $sql = "SELECT id, name, code FROM roles WHERE deactivated_at IS NULL ORDER BY name ASC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, name, code FROM roles WHERE id = :id AND deactivated_at IS NULL");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function codeExists(string $code): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM roles WHERE code = :code");
        $stmt->execute([':code' => $code]);
        return $stmt->fetchColumn() !== false;
    }

    public function create(string $name, string $code): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO roles (name, code) VALUES (:name, :code) RETURNING id");
        $stmt->execute([':name' => $name, ':code' => $code]);
        return (int) $stmt->fetchColumn();
    }

    /** Returns every active operation (fixed order) annotated with whether the given role currently has it granted. */
    public function listOperationsForRole(int $roleId): array
    {
        $sql = <<<SQL
            SELECT o.id, o.name, o.code, (ro.id IS NOT NULL) AS granted
            FROM operations o
            LEFT JOIN role_operations ro
                   ON ro.operation_id = o.id
                  AND ro.role_id = :role_id
                  AND ro.deactivated_at IS NULL
            WHERE o.deactivated_at IS NULL
            ORDER BY o.id ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':role_id' => $roleId]);
        return $stmt->fetchAll();
    }

    public function operationCode(int $operationId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT code FROM operations WHERE id = :id");
        $stmt->execute([':id' => $operationId]);
        $code = $stmt->fetchColumn();
        return $code !== false ? (string) $code : null;
    }

    public function grantOperation(int $roleId, int $operationId): void
    {
        $sql = <<<SQL
            INSERT INTO role_operations (role_id, operation_id)
            VALUES (:role_id, :operation_id)
            ON CONFLICT (role_id, operation_id) DO UPDATE SET deactivated_at = NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':role_id' => $roleId, ':operation_id' => $operationId]);
    }

    public function revokeOperation(int $roleId, int $operationId): void
    {
        $sql = <<<SQL
            UPDATE role_operations
            SET deactivated_at = CURRENT_TIMESTAMP
            WHERE role_id = :role_id AND operation_id = :operation_id AND deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':role_id' => $roleId, ':operation_id' => $operationId]);
    }

    /** True when no other active role currently grants the given operation code. */
    public function isOnlyRoleGrantingOperation(int $roleId, string $operationCode): bool
    {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM role_operations ro
            JOIN operations o ON o.id = ro.operation_id
            JOIN roles r      ON r.id = ro.role_id
            WHERE o.code = :code
              AND ro.deactivated_at IS NULL
              AND r.deactivated_at IS NULL
              AND ro.role_id != :role_id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':code' => $operationCode, ':role_id' => $roleId]);
        return (int) $stmt->fetchColumn() === 0;
    }
}
