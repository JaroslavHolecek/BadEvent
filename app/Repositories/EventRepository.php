<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class EventRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Returns all events (for archive), newest first. */
    public function listAll(int $limit = 100, int $offset = 0): array
    {
        $sql = <<<SQL
            SELECT e.id, e.name, e.note, e.created_at,
                   COUNT(a.id) AS action_count,
                   MIN(a.public_start_at) AS first_action_at,
                   MAX(a.public_end_at)   AS last_action_at
            FROM events e
            LEFT JOIN actions a ON a.event_id = e.id
            GROUP BY e.id
            ORDER BY e.created_at DESC
            LIMIT :limit OFFSET :offset
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Returns events that have at least one non-terminal (not_agreed/cancelled/closed) action, newest first. */
    public function listActive(int $limit = 100, int $offset = 0): array
    {
        $sql = <<<SQL
            SELECT e.id, e.name, e.note, e.created_at,
                   COUNT(a.id) AS action_count,
                   MIN(a.public_start_at) AS first_action_at,
                   MAX(a.public_end_at)   AS last_action_at
            FROM events e
            LEFT JOIN actions a ON a.event_id = e.id
            WHERE EXISTS (
                SELECT 1 FROM actions a2
                JOIN action_statuses s2 ON s2.id = a2.actual_status
                WHERE a2.event_id = e.id
                  AND s2.code NOT IN ('not_agreed', 'cancelled', 'closed')
            )
            GROUP BY e.id
            ORDER BY e.created_at DESC
            LIMIT :limit OFFSET :offset
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Returns events eligible to be assigned to an action: those with at least one
     * non-terminal action, newest first. $includeEventId (if given) is always included,
     * so an action's currently-assigned event never disappears from its own edit form.
     */
    public function listAvailableForAssignment(?int $includeEventId = null): array
    {
        $sql = <<<SQL
            SELECT e.id, e.name
            FROM events e
            WHERE EXISTS (
                SELECT 1 FROM actions a
                JOIN action_statuses s ON s.id = a.actual_status
                WHERE a.event_id = e.id
                  AND s.code NOT IN ('not_agreed', 'cancelled', 'closed')
            )
            OR e.id = :include
            ORDER BY e.name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':include' => $includeEventId ?? 0]);
        return $stmt->fetchAll();
    }

    public function countActions(int $eventId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM actions WHERE event_id = :id');
        $stmt->execute([':id' => $eventId]);
        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM events WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function getActions(int $eventId): array
    {
        $sql = <<<SQL
            SELECT a.id, a.name, a.public_start_at, a.public_end_at,
                   s.code AS status_code, s.name AS status_name
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            WHERE a.event_id = :event_id
            ORDER BY a.public_start_at ASC NULLS LAST
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':event_id' => $eventId]);
        return $stmt->fetchAll();
    }

    public function create(string $name, ?string $note = null): int
    {
        $sql = 'INSERT INTO events (name, note) VALUES (:name, :note) RETURNING id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':name' => $name, ':note' => $note]);
        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, string $name, ?string $note = null): void
    {
        $sql = 'UPDATE events SET name = :name, note = :note WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id, ':name' => $name, ':note' => $note]);
    }
}
