<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Who codes/uncodes the building on a given day (primary + substitute), plus an optional note for
 * that day shown to every user on their dashboard. Only ever needs to hold
 * roughly 2 months back and 2 months forward of data — see cleanup_old_building_code_duty() in
 * the schema, called here on every use so the table never needs a separate cron job to stay small.
 */
final class BuildingCodeDutyRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('SELECT cleanup_old_building_code_duty()');
    }

    /** Returns the duty row (with resolved names) for a single date, or null if nothing is set. */
    public function forDate(string $date): ?array
    {
        $sql = <<<SQL
            SELECT d.duty_date, d.primary_user_id, d.substitute_user_id, d.note,
                   pp.full_name AS primary_name, sp.full_name AS substitute_name
            FROM building_code_duty d
            LEFT JOIN app_users pu ON pu.id = d.primary_user_id
            LEFT JOIN persons pp   ON pp.id = pu.person_id
            LEFT JOIN app_users su ON su.id = d.substitute_user_id
            LEFT JOIN persons sp   ON sp.id = su.person_id
            WHERE d.duty_date = :date
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':date' => $date]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Returns every set row between $from and $to (inclusive), with resolved names, date-ordered. */
    public function listRange(string $from, string $to): array
    {
        $sql = <<<SQL
            SELECT d.duty_date, d.primary_user_id, d.substitute_user_id, d.note,
                   pp.full_name AS primary_name, sp.full_name AS substitute_name
            FROM building_code_duty d
            LEFT JOIN app_users pu ON pu.id = d.primary_user_id
            LEFT JOIN persons pp   ON pp.id = pu.person_id
            LEFT JOIN app_users su ON su.id = d.substitute_user_id
            LEFT JOIN persons sp   ON sp.id = su.person_id
            WHERE d.duty_date BETWEEN :from AND :to
            ORDER BY d.duty_date ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':from' => $from, ':to' => $to]);
        return $stmt->fetchAll();
    }

    /** Sets a day's primary/substitute duty and note, or clears the row entirely when all three are empty. */
    public function set(string $date, ?int $primaryUserId, ?int $substituteUserId, ?string $note = null): void
    {
        if ($primaryUserId === null && $substituteUserId === null && $note === null) {
            $stmt = $this->pdo->prepare('DELETE FROM building_code_duty WHERE duty_date = :date');
            $stmt->execute([':date' => $date]);
            return;
        }

        $sql = <<<SQL
            INSERT INTO building_code_duty (duty_date, primary_user_id, substitute_user_id, note)
            VALUES (:date, :primary_user_id, :substitute_user_id, :note)
            ON CONFLICT (duty_date) DO UPDATE
                SET primary_user_id    = EXCLUDED.primary_user_id,
                    substitute_user_id = EXCLUDED.substitute_user_id,
                    note               = EXCLUDED.note,
                    updated_at         = CURRENT_TIMESTAMP
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':date'               => $date,
            ':primary_user_id'    => $primaryUserId,
            ':substitute_user_id' => $substituteUserId,
            ':note'               => $note,
        ]);
    }
}
