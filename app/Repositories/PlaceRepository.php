<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class PlaceRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM places WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Returns all places (active and deactivated) for the admin management page. */
    public function listAll(): array
    {
        $sql = <<<SQL
            SELECT p.id, p.name, p.abbreviation, p.capacity, p.note, p.deactivated_at,
                   STRING_AGG(pe.full_name, ', ' ORDER BY pe.full_name) AS managers
            FROM places p
            LEFT JOIN place_managers pm ON pm.place_id = p.id AND pm.deactivated_at IS NULL
            LEFT JOIN app_users au      ON au.id = pm.user_id
            LEFT JOIN persons pe        ON pe.id = au.person_id
            GROUP BY p.id
            ORDER BY p.name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Returns all active places for select inputs. */
    public function listActive(): array
    {
        $sql = <<<SQL
            SELECT id, name, abbreviation, capacity
            FROM places
            WHERE deactivated_at IS NULL
            ORDER BY name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function create(string $name, ?string $abbreviation, ?int $capacity, ?string $note): int
    {
        $sql = <<<SQL
            INSERT INTO places (name, abbreviation, capacity, note)
            VALUES (:name, :abbreviation, :capacity, :note)
            RETURNING id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':name'         => $name,
            ':abbreviation' => $abbreviation,
            ':capacity'     => $capacity,
            ':note'         => $note,
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function deactivate(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE places SET deactivated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function activate(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE places SET deactivated_at = NULL WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function update(int $id, string $name, ?string $abbreviation, ?int $capacity, ?string $note): void
    {
        $sql = <<<SQL
            UPDATE places
            SET name = :name, abbreviation = :abbreviation, capacity = :capacity, note = :note
            WHERE id = :id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id'           => $id,
            ':name'         => $name,
            ':abbreviation' => $abbreviation,
            ':capacity'     => $capacity,
            ':note'         => $note,
        ]);
    }

    /** Returns the active managers assigned to a place. */
    public function getManagers(int $placeId): array
    {
        $sql = <<<SQL
            SELECT pm.user_id, p.full_name, p.short_name
            FROM place_managers pm
            JOIN app_users au ON au.id = pm.user_id
            LEFT JOIN persons p ON p.id = au.person_id
            WHERE pm.place_id = :place_id AND pm.deactivated_at IS NULL
            ORDER BY p.full_name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':place_id' => $placeId]);
        return $stmt->fetchAll();
    }

    /**
     * For the "Přehled místností" export: returns every active place that has at least one
     * confirmed action (action_places, not the unconfirmed action_places_desire queue) whose
     * public window overlaps [$dateFrom, $dateTo] — either bound may be null for "unbounded".
     * Places with no matching action are omitted entirely, per the export's spec.
     *
     * @return array<int, array{id:int, name:string, abbreviation:?string, actions:array<int,array{id:int,name:string,public_start_at:?string,public_end_at:?string}>}>
     */
    public function listWithActionsInRange(?string $dateFrom, ?string $dateTo): array
    {
        $sql = <<<SQL
            SELECT pl.id AS place_id, pl.name AS place_name, pl.abbreviation AS place_abbreviation,
                   a.id AS action_id, a.name AS action_name,
                   a.public_start_at, a.public_end_at, a.staff_start_at, a.staff_end_at
            FROM places pl
            JOIN action_places ap ON ap.place_id = pl.id
            JOIN actions a        ON a.id = ap.action_id
            WHERE pl.deactivated_at IS NULL
              AND (?::timestamp IS NULL OR COALESCE(a.public_end_at, a.public_start_at) >= ?::timestamp)
              AND (?::timestamp IS NULL OR a.public_start_at <= ?::timestamp)
            ORDER BY pl.name ASC, a.public_start_at ASC NULLS LAST
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$dateFrom, $dateFrom, $dateTo, $dateTo]);

        $places = [];
        foreach ($stmt->fetchAll() as $row) {
            $placeId = (int) $row['place_id'];
            if (!isset($places[$placeId])) {
                $places[$placeId] = [
                    'id'           => $placeId,
                    'name'         => $row['place_name'],
                    'abbreviation' => $row['place_abbreviation'],
                    'actions'      => [],
                ];
            }
            $places[$placeId]['actions'][] = [
                'id'              => (int) $row['action_id'],
                'name'            => $row['action_name'],
                'public_start_at' => $row['public_start_at'],
                'public_end_at'   => $row['public_end_at'],
                'staff_start_at'  => $row['staff_start_at'],
                'staff_end_at'    => $row['staff_end_at'],
            ];
        }

        return array_values($places);
    }

    /** Replaces this place's set of managers with $userIds, reactivating previously revoked assignments where possible. */
    public function syncManagers(int $placeId, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        foreach ($userIds as $userId) {
            $sql = <<<SQL
                INSERT INTO place_managers (place_id, user_id)
                VALUES (:place_id, :user_id)
                ON CONFLICT (place_id, user_id) DO UPDATE SET deactivated_at = NULL
            SQL;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':place_id' => $placeId, ':user_id' => $userId]);
        }

        if (empty($userIds)) {
            $stmt = $this->pdo->prepare(
                'UPDATE place_managers SET deactivated_at = CURRENT_TIMESTAMP WHERE place_id = :place_id AND deactivated_at IS NULL'
            );
            $stmt->execute([':place_id' => $placeId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = <<<SQL
            UPDATE place_managers
            SET deactivated_at = CURRENT_TIMESTAMP
            WHERE place_id = ? AND user_id NOT IN ({$placeholders}) AND deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$placeId, ...$userIds]);
    }
}
