<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class UserRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function findById(int $id): ?array
    {
        $sql = <<<SQL
            SELECT au.id, au.note, au.created_at, au.deactivated_at, au.person_id,
                   p.full_name, p.short_name, bl.email
            FROM app_users au
            LEFT JOIN persons p   ON p.id = au.person_id
            LEFT JOIN bl_login bl ON bl.id = au.id
            WHERE au.id = :id AND au.deleted_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listAll(): array
    {
        $sql = <<<SQL
            SELECT au.id, au.person_id, au.created_at, au.deactivated_at,
                   p.full_name, p.short_name,
                   STRING_AGG(r.name, ', ' ORDER BY r.name) AS roles,
                   STRING_AGG(r.id::text, ',' ORDER BY r.name) AS role_ids
            FROM app_users au
            LEFT JOIN persons p    ON p.id  = au.person_id
            LEFT JOIN user_roles ur ON ur.user_id = au.id AND ur.deactivated_at IS NULL
            LEFT JOIN roles r       ON r.id  = ur.role_id AND r.deactivated_at IS NULL
            WHERE au.deleted_at IS NULL
            GROUP BY au.id, au.person_id, p.full_name, p.short_name
            ORDER BY p.full_name ASC NULLS LAST
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Returns all active (non-deleted, non-deactivated) users for selection lists. */
    public function listActive(): array
    {
        $sql = <<<SQL
            SELECT au.id, p.full_name, p.short_name
            FROM app_users au
            LEFT JOIN persons p ON p.id = au.person_id
            WHERE au.deleted_at IS NULL AND au.deactivated_at IS NULL
            ORDER BY p.full_name ASC NULLS LAST
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Creates a new app_user record linked to the given BadLogin user ID. */
    public function createFromLogin(int $loginId, int $personId): void
    {
        $sql = <<<SQL
            INSERT INTO app_users (id, person_id)
            VALUES (:id, :person_id)
            ON CONFLICT (id) DO UPDATE SET person_id = EXCLUDED.person_id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $loginId, ':person_id' => $personId]);
    }

    /** Returns the app_user (id + login email) linked to a person, or null if the person has no account. */
    public function findByPersonId(int $personId): ?array
    {
        $sql = <<<SQL
            SELECT au.id, bl.email
            FROM app_users au
            LEFT JOIN bl_login bl ON bl.id = au.id
            WHERE au.person_id = :person_id AND au.deleted_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':person_id' => $personId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function countActive(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM app_users WHERE deleted_at IS NULL AND deactivated_at IS NULL");
        return (int) $stmt->fetchColumn();
    }

    /**
     * Registers a self-service user account.
     * Creates a person record, links it to app_users.
     * If this is the very first account, the administrator role is assigned automatically.
     */
    public function registerNewUser(int $loginId, string $fullName): void
    {
        $this->pdo->beginTransaction();
        try {
            $isFirst = (int) $this->pdo
                ->query("SELECT COUNT(*) FROM app_users WHERE deleted_at IS NULL")
                ->fetchColumn() === 0;

            $stmt = $this->pdo->prepare("INSERT INTO persons (full_name) VALUES (:full_name) RETURNING id");
            $stmt->execute([':full_name' => $fullName]);
            $personId = (int) $stmt->fetchColumn();

            $this->pdo->prepare("INSERT INTO app_users (id, person_id) VALUES (:id, :person_id)")
                ->execute([':id' => $loginId, ':person_id' => $personId]);

            if ($isFirst) {
                $this->pdo->prepare("
                    INSERT INTO user_roles (user_id, role_id)
                    SELECT :user_id, r.id FROM roles r WHERE r.code = 'administrator'
                ")->execute([':user_id' => $loginId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Returns true when no admin exists other than this user. */
    public function isLastAdmin(int $userId): bool
    {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE r.code = 'administrator'
              AND ur.deactivated_at IS NULL
              AND ur.user_id != :user_id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /** Returns display name + login email for the site header. */
    public function findHeaderInfo(int $id): ?array
    {
        $sql = <<<SQL
            SELECT p.full_name, p.short_name, bl.email
            FROM app_users au
            LEFT JOIN persons p  ON p.id = au.person_id
            LEFT JOIN bl_login bl ON bl.id = au.id
            WHERE au.id = :id AND au.deleted_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Returns the data needed for the "my profile" page: linked person, login email, and whether a password is set. */
    public function findProfileData(int $id): ?array
    {
        $sql = <<<SQL
            SELECT au.id, au.person_id, p.full_name, p.short_name,
                   bl.email, (bl.password_hash IS NOT NULL AND bl.password_hash != '') AS has_password
            FROM app_users au
            LEFT JOIN persons p   ON p.id = au.person_id
            LEFT JOIN bl_login bl ON bl.id = au.id
            WHERE au.id = :id AND au.deleted_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function setActiveRoleId(int $userId, int $roleId): void
    {
        $stmt = $this->pdo->prepare("UPDATE app_users SET active_role_id = :role_id WHERE id = :id");
        $stmt->execute([':role_id' => $roleId, ':id' => $userId]);
    }

    public function activeRoleId(int $userId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT active_role_id FROM app_users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $roleId = $stmt->fetchColumn();
        return ($roleId !== false && $roleId !== null) ? (int) $roleId : null;
    }

    /** Assigns a role to a user, reactivating a previously revoked assignment if one exists. */
    public function assignRole(int $userId, int $roleId): void
    {
        $sql = <<<SQL
            INSERT INTO user_roles (user_id, role_id)
            VALUES (:user_id, :role_id)
            ON CONFLICT (user_id, role_id) DO UPDATE SET deactivated_at = NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':role_id' => $roleId]);
    }

    public function removeRole(int $userId, int $roleId): void
    {
        $sql = <<<SQL
            UPDATE user_roles
            SET deactivated_at = CURRENT_TIMESTAMP
            WHERE user_id = :user_id AND role_id = :role_id AND deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':role_id' => $roleId]);
    }

    /** True when the user currently has the given role code assigned and active. */
    public function hasRoleCode(int $userId, string $roleCode): bool
    {
        $sql = <<<SQL
            SELECT 1
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id AND r.code = :code AND ur.deactivated_at IS NULL
            LIMIT 1
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':code' => $roleCode]);
        return $stmt->fetchColumn() !== false;
    }

    /** Soft-deletes the user (and their BadLogin account) via the DB-side soft_delete_app_user function. */
    public function softDelete(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT soft_delete_app_user(:id)');
        $stmt->execute([':id' => $id]);
    }

    public function deactivate(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE app_users SET deactivated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function activate(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE app_users SET deactivated_at = NULL WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function updateNote(int $id, ?string $note): void
    {
        $stmt = $this->pdo->prepare('UPDATE app_users SET note = :note WHERE id = :id');
        $stmt->execute([':id' => $id, ':note' => $note]);
    }

    /** Returns active users who currently have the given role code assigned (for staff-assignment candidate lists). */
    public function listByRoleCode(string $roleCode): array
    {
        $sql = <<<SQL
            SELECT DISTINCT au.id, au.person_id, p.full_name, p.short_name
            FROM app_users au
            LEFT JOIN persons p     ON p.id  = au.person_id
            JOIN user_roles ur      ON ur.user_id = au.id AND ur.deactivated_at IS NULL
            JOIN roles r            ON r.id  = ur.role_id AND r.deactivated_at IS NULL AND r.code = :code
            WHERE au.deleted_at IS NULL AND au.deactivated_at IS NULL
            ORDER BY p.full_name ASC NULLS LAST
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':code' => $roleCode]);
        return $stmt->fetchAll();
    }
}
