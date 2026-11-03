<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\UserRepository;
use PDO;

final class PermissionService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * Returns the role the user is currently browsing the app as.
     * Self-heals: if no active role is stored yet, or it's no longer assigned
     * to the user, falls back to their first assigned role and persists it.
     */
    public function activeRole(int $userId): ?array
    {
        $sql = <<<SQL
            SELECT r.id, r.name, r.code
            FROM app_users au
            JOIN roles r       ON r.id = au.active_role_id
            JOIN user_roles ur ON ur.user_id = au.id AND ur.role_id = r.id AND ur.deactivated_at IS NULL
            WHERE au.id = :user_id AND r.deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $role = $stmt->fetch();
        if ($role !== false) {
            return $role;
        }

        $roles = $this->userRoles($userId);
        if (empty($roles)) {
            return null;
        }

        $fallback = $roles[0];
        (new UserRepository())->setActiveRoleId($userId, (int) $fallback['id']);
        return $fallback;
    }

    /** Switches the user's active role. Returns false when the role isn't assigned to them. */
    public function switchRole(int $userId, int $roleId): bool
    {
        $sql = <<<SQL
            SELECT 1
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id AND ur.role_id = :role_id
              AND ur.deactivated_at IS NULL AND r.deactivated_at IS NULL
            LIMIT 1
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':role_id' => $roleId]);
        if ($stmt->fetchColumn() === false) {
            return false;
        }

        (new UserRepository())->setActiveRoleId($userId, $roleId);
        return true;
    }

    /** Returns true when the user's currently active role has the given operation code. */
    public function can(int $userId, string $operationCode): bool
    {
        $role = $this->activeRole($userId);
        if ($role === null) {
            return false;
        }

        $sql = <<<SQL
            SELECT 1
            FROM role_operations ro
            JOIN operations o ON o.id = ro.operation_id
            WHERE ro.role_id = :role_id
              AND o.code     = :code
              AND ro.deactivated_at IS NULL
              AND o.deactivated_at  IS NULL
            LIMIT 1
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':role_id' => $role['id'], ':code' => $operationCode]);
        return $stmt->fetchColumn() !== false;
    }

    /** Returns all operation codes available under the user's currently active role. */
    public function allowedOperations(int $userId): array
    {
        $role = $this->activeRole($userId);
        if ($role === null) {
            return [];
        }

        $sql = <<<SQL
            SELECT DISTINCT o.code
            FROM role_operations ro
            JOIN operations o ON o.id = ro.operation_id
            WHERE ro.role_id = :role_id
              AND ro.deactivated_at IS NULL
              AND o.deactivated_at  IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':role_id' => $role['id']]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** Returns all roles assigned to the user (for the role switcher), ordered for stable display. */
    public function userRoles(int $userId): array
    {
        $sql = <<<SQL
            SELECT r.id, r.name, r.code
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
              AND ur.deactivated_at IS NULL
              AND r.deactivated_at  IS NULL
            ORDER BY r.name ASC
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /** Returns true when the user's currently active role is 'administrator'. */
    public function isAdmin(int $userId): bool
    {
        $role = $this->activeRole($userId);
        return $role !== null && $role['code'] === 'administrator';
    }
}
