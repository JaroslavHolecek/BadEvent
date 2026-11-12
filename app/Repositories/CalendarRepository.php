<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class CalendarRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * Returns all actions taking place (public_start_at … public_end_at) at some point of the given month —
     * also those begun in an earlier month and still running — whose current status is one of $statusCodes.
     * Groups action data with their assigned places.
     */
    public function actionsForMonth(int $year, int $month, array $statusCodes, ?int $involvedUserId = null, array $placeIds = []): array
    {
        if (empty($statusCodes)) {
            return [];
        }

        $from = sprintf('%04d-%02d-01', $year, $month);
        $to   = date('Y-m-d', mktime(0, 0, 0, $month + 1, 1, $year));
        $placeholders = implode(',', array_fill(0, count($statusCodes), '?'));
        $involved = $involvedUserId !== null ? 'AND ' . ActionRepository::involvementCondition($involvedUserId) : '';
        $placeFilter = '';
        if (!empty($placeIds)) {
            $placePlaceholders = implode(',', array_fill(0, count($placeIds), '?'));
            $placeFilter = "AND EXISTS (SELECT 1 FROM action_places apf WHERE apf.action_id = a.id AND apf.place_id IN ({$placePlaceholders}))";
        }

        $sql = "SELECT a.id, a.name,
                       a.public_start_at, a.public_end_at,
                       a.staff_start_at,  a.staff_end_at,
                       s.code AS status_code, s.name AS status_name,
                       STRING_AGG(pl.abbreviation, ', ') AS places
                FROM actions a
                JOIN action_statuses s ON s.id = a.actual_status
                LEFT JOIN action_places ap ON ap.action_id = a.id
                LEFT JOIN places pl        ON pl.id = ap.place_id
                WHERE a.public_start_at < ?
                  AND COALESCE(a.public_end_at, a.public_start_at) >= ?
                  AND s.code IN ({$placeholders})
                  {$involved}
                  {$placeFilter}
                GROUP BY a.id, s.code, s.name
                ORDER BY a.public_start_at ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$to, $from, ...$statusCodes, ...$placeIds]);
        return $stmt->fetchAll();
    }

    /**
     * SQL condition: action $alias occupies its room at some point of the month — its whole span (earliest
     * to latest of the staff/public times, NULLs skipped by LEAST/GREATEST) overlaps [:{$p}_from, :{$p}_to).
     * Not just "starts in the month": a multi-day action begun last month still occupies the room now.
     */
    private function overlapsMonth(string $alias, string $p): string
    {
        return "LEAST({$alias}.staff_start_at, {$alias}.public_start_at) < :{$p}_to"
            . " AND GREATEST({$alias}.staff_end_at, {$alias}.public_end_at, {$alias}.staff_start_at, {$alias}.public_start_at) >= :{$p}_from";
    }

    /**
     * Returns place occupancy for a given month.
     * Each row: place data + confirmed and queued (unconfirmed room request) action data during that month
     * (any action whose time span overlaps the month, see overlapsMonth()); cancelled / not-agreed actions excluded.
     */
    public function placeOccupancyForMonth(int $year, int $month): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to   = date('Y-m-d', mktime(0, 0, 0, $month + 1, 1, $year));

        $confirmedSql = <<<SQL
            SELECT pl.id AS place_id, pl.name AS place_name, pl.abbreviation,
                   a.id AS action_id, a.name AS action_name,
                   a.public_start_at, a.public_end_at,
                   a.staff_start_at,  a.staff_end_at,
                   s.code AS status_code,
                   FALSE AS is_queued, NULL::timestamp AS requested_at
            FROM places pl
            LEFT JOIN action_places ap ON ap.place_id = pl.id
            -- Actions + their status as one inner join in parentheses: a cancelled / not-agreed action drops
            -- out entirely (a separate LEFT JOIN on the status used to keep it, just with an empty status),
            -- while every active room is still listed, even with nothing booked.
            LEFT JOIN (
                actions a
                JOIN action_statuses s ON s.id = a.actual_status
                    AND s.code NOT IN ('not_agreed', 'cancelled')
            ) ON a.id = ap.action_id
                AND {$this->overlapsMonth('a', 'c')}
            WHERE pl.deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($confirmedSql);
        $stmt->execute([':c_from' => $from, ':c_to' => $to]);
        $rows = $stmt->fetchAll();

        $queuedSql = <<<SQL
            SELECT pl.id AS place_id, pl.name AS place_name, pl.abbreviation,
                   a.id AS action_id, a.name AS action_name,
                   a.public_start_at, a.public_end_at,
                   a.staff_start_at,  a.staff_end_at,
                   s.code AS status_code,
                   TRUE AS is_queued, d.created_at AS requested_at
            FROM places pl
            JOIN action_places_desire d ON d.place_id = pl.id
            JOIN actions a              ON a.id = d.action_id
                AND {$this->overlapsMonth('a', 'q')}
            JOIN action_statuses s      ON s.id = a.actual_status
                AND s.code NOT IN ('not_agreed', 'cancelled')
            WHERE pl.deactivated_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM action_places ap2
                  WHERE ap2.action_id = d.action_id AND ap2.place_id = d.place_id
              )
            ORDER BY d.created_at ASC
        SQL;
        $stmt = $this->pdo->prepare($queuedSql);
        $stmt->execute([':q_from' => $from, ':q_to' => $to]);
        $rows = array_merge($rows, $stmt->fetchAll());

        return $this->groupByPlace($rows);
    }

    private function groupByPlace(array $rows): array
    {
        $places = [];
        foreach ($rows as $row) {
            $pid = $row['place_id'];
            if (!isset($places[$pid])) {
                $places[$pid] = [
                    'id'           => $pid,
                    'name'         => $row['place_name'],
                    'abbreviation' => $row['abbreviation'],
                    'actions'      => [],
                ];
            }
            if ($row['action_id'] !== null) {
                $places[$pid]['actions'][] = [
                    'id'              => $row['action_id'],
                    'name'            => $row['action_name'],
                    'public_start_at' => $row['public_start_at'],
                    'public_end_at'   => $row['public_end_at'],
                    'staff_start_at'  => $row['staff_start_at'],
                    'staff_end_at'    => $row['staff_end_at'],
                    'status_code'     => $row['status_code'],
                    'is_queued'       => (bool) $row['is_queued'],
                    'requested_at'    => $row['requested_at'],
                ];
            }
        }

        foreach ($places as &$place) {
            usort($place['actions'], fn($a, $b) => [$a['public_start_at'], $a['is_queued'], $a['requested_at']]
                <=> [$b['public_start_at'], $b['is_queued'], $b['requested_at']]);
        }
        unset($place);

        return array_values($places);
    }
}
