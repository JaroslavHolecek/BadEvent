<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class ActionRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function findById(int $id): ?array
    {
        $sql = <<<SQL
            SELECT a.*,
                   s.code AS status_code,
                   s.name AS status_name,
                   e.name AS event_name
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            JOIN events e          ON e.id = a.event_id
            WHERE a.id = :id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['photograph'] = self::normalizeBool($row['photograph']);
        $row['not_main_organizer'] = self::normalizeBool($row['not_main_organizer']);
        return $row;
    }

    /** Returns actions where the user is an organizer or substitute. */
    /**
     * SQL condition (against `actions a`) true when the user is involved in the action in any way:
     * organizer, substitute or helper ("pomocník" — action_staff stores the person, so it is
     * matched through app_users.person_id). $userId is cast to int, so it is safe to inline.
     */
    public static function involvementCondition(int $userId): string
    {
        return "(EXISTS (SELECT 1 FROM action_organizers x WHERE x.action_id = a.id AND x.user_id = {$userId} AND x.deactivated_at IS NULL)
              OR EXISTS (SELECT 1 FROM action_substitutes x WHERE x.action_id = a.id AND x.user_id = {$userId} AND x.deactivated_at IS NULL)
              OR EXISTS (SELECT 1 FROM action_staff x JOIN app_users u ON u.person_id = x.person_id
                         WHERE x.action_id = a.id AND u.id = {$userId} AND x.deactivated_at IS NULL))";
    }

    /** The user's own current actions (organizer/substitute/helper) — still Rozjednaná or Potvrzená. */
    /** A draft this close to its start (days) counts as "approaching" — the first draft reminder, i.e. max of the draft_reminder_days setting. */
    public static function draftSoonDays(): int
    {
        return max(array_map('intval', (array) \App\Services\NotificationService::setting('draft_reminder_days')));
    }

    public function listForUser(int $userId): array
    {
        $uid  = (int) $userId;
        $cond = self::involvementCondition($uid);
        $soon = self::draftSoonDays();
        $sql = <<<SQL
            SELECT a.id, a.name, a.public_start_at, a.public_end_at,
                   s.code AS status_code, s.name AS status_name,
                   CASE
                       WHEN EXISTS (SELECT 1 FROM action_organizers x WHERE x.action_id = a.id AND x.user_id = {$uid} AND x.deactivated_at IS NULL) THEN 'organizer'
                       WHEN EXISTS (SELECT 1 FROM action_substitutes x WHERE x.action_id = a.id AND x.user_id = {$uid} AND x.deactivated_at IS NULL) THEN 'substitute'
                       ELSE 'staff'
                   END AS user_role,
                   (EXISTS (SELECT 1 FROM action_places_desire qd
                           WHERE qd.action_id = a.id AND qd.first_at_queue_at IS NOT NULL
                             AND NOT EXISTS (SELECT 1 FROM action_places qp
                                             WHERE qp.action_id = qd.action_id AND qp.place_id = qd.place_id)))::int AS queue_first
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            WHERE {$cond}
              AND (s.code = 'confirmed'
                   -- Rozjednaná only once it's within the first reminder's window (default 30 days) of its start (distant drafts need no action yet);
                   -- a draft with no start date set can't be judged, so it stays visible.
                   OR (s.code = 'draft' AND (a.public_start_at IS NULL OR a.public_start_at::date - CURRENT_DATE <= {$soon})))
            ORDER BY a.public_start_at ASC NULLS LAST
        SQL;
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Actions of anyone that need administrator attention: ended (by public end) but still
     * draft/confirmed, draft within draftSoonDays() of its start, or completed and waiting to be closed.
     * $now is 'Y-m-d H:i:s' in app time.
     */
    public function listNeedingAttention(string $now): array
    {
        $sql = <<<SQL
            SELECT a.id, a.name, a.public_start_at, a.public_end_at,
                   s.code AS status_code, s.name AS status_name,
                   NULL::text AS user_role,
                   (EXISTS (SELECT 1 FROM action_places_desire qd
                           WHERE qd.action_id = a.id AND qd.first_at_queue_at IS NOT NULL
                             AND NOT EXISTS (SELECT 1 FROM action_places qp
                                             WHERE qp.action_id = qd.action_id AND qp.place_id = qd.place_id)))::int AS queue_first
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            WHERE s.code = 'completed'
               OR (s.code IN ('draft', 'confirmed') AND a.public_end_at < :now)
               OR (s.code = 'draft' AND a.public_start_at::date - CURRENT_DATE <= :soon_days)
            ORDER BY a.public_start_at ASC NULLS LAST
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':now' => $now, ':soon_days' => self::draftSoonDays()]);
        return $stmt->fetchAll();
    }

    /** Returns upcoming actions in a date range for calendar view. */
    public function listInRange(string $from, string $to): array
    {
        $sql = <<<SQL
            SELECT a.id, a.name, a.public_start_at, a.public_end_at,
                   s.code AS status_code, s.name AS status_name
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            WHERE a.public_start_at >= :from
              AND a.public_start_at <  :to
              AND s.code NOT IN ('not_agreed', 'cancelled')
            ORDER BY a.public_start_at ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':from' => $from, ':to' => $to]);
        return $stmt->fetchAll();
    }

    public function create(array $data, int $draftStatusId): int
    {
        $sql = <<<SQL
            INSERT INTO actions
                (event_id, name, staff_start_at, staff_end_at, public_start_at, public_end_at, capacity, note, photograph, photograph_note, not_main_organizer, actual_status)
            VALUES
                (:event_id, :name, :staff_start_at, :staff_end_at, :public_start_at, :public_end_at, :capacity, :note, :photograph, :photograph_note, :not_main_organizer, :actual_status)
            RETURNING id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':event_id'           => $data['event_id'],
            ':name'               => $data['name'],
            ':staff_start_at'     => $data['staff_start_at'] ?? null,
            ':staff_end_at'       => $data['staff_end_at']   ?? null,
            ':public_start_at'    => $data['public_start_at'] ?? null,
            ':public_end_at'      => $data['public_end_at']  ?? null,
            ':capacity'           => $data['capacity']        ?? null,
            ':note'               => $data['note']            ?? null,
            ':photograph'         => !empty($data['photograph']) ? 't' : 'f',
            ':photograph_note'    => $data['photograph_note']    ?? null,
            ':not_main_organizer' => !empty($data['not_main_organizer']) ? 't' : 'f',
            ':actual_status'      => $draftStatusId,
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, array $data): void
    {
        $sql = <<<SQL
            UPDATE actions
            SET name               = :name,
                event_id           = :event_id,
                staff_start_at     = :staff_start_at,
                staff_end_at       = :staff_end_at,
                public_start_at    = :public_start_at,
                public_end_at      = :public_end_at,
                capacity           = :capacity,
                note               = :note,
                photograph         = :photograph,
                photograph_note    = :photograph_note,
                not_main_organizer = :not_main_organizer
            WHERE id = :id
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id'                 => $id,
            ':name'               => $data['name'],
            ':event_id'           => $data['event_id'],
            ':staff_start_at'     => $data['staff_start_at'] ?? null,
            ':staff_end_at'       => $data['staff_end_at']   ?? null,
            ':public_start_at'    => $data['public_start_at'] ?? null,
            ':public_end_at'      => $data['public_end_at']  ?? null,
            ':capacity'           => $data['capacity']        ?? null,
            ':note'               => $data['note']            ?? null,
            ':photograph'         => !empty($data['photograph']) ? 't' : 'f',
            ':photograph_note'    => $data['photograph_note']    ?? null,
            ':not_main_organizer' => !empty($data['not_main_organizer']) ? 't' : 'f',
        ]);
    }

    /**
     * Returns actions whose current status is one of $statusCodes, newest public_start_at first,
     * optionally restricted to those whose public time range overlaps [$dateFrom, $dateTo]
     * (either bound null/omitted means unbounded in that direction). With $involvedUserId set, only
     * actions that user is involved in (see involvementCondition()).
     */
    public function listByStatuses(array $statusCodes, ?string $dateFrom = null, ?string $dateTo = null, int $limit = 200, int $offset = 0, ?int $involvedUserId = null, array $placeIds = []): array
    {
        if (empty($statusCodes)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($statusCodes), '?'));
        $involved = $involvedUserId !== null ? 'AND ' . self::involvementCondition($involvedUserId) : '';
        $placeFilter = '';
        if (!empty($placeIds)) {
            $placePlaceholders = implode(',', array_fill(0, count($placeIds), '?'));
            $placeFilter = "AND EXISTS (SELECT 1 FROM action_places apf WHERE apf.action_id = a.id AND apf.place_id IN ({$placePlaceholders}))";
        }
        $sql = "SELECT a.id, a.name, a.public_start_at, a.public_end_at,
                       a.staff_start_at, a.staff_end_at, a.capacity,
                       s.code AS status_code, s.name AS status_name,
                       e.name AS event_name
                FROM actions a
                JOIN action_statuses s ON s.id = a.actual_status
                JOIN events e          ON e.id = a.event_id
                WHERE s.code IN ({$placeholders})
                  AND (?::timestamp IS NULL OR COALESCE(a.public_end_at, a.public_start_at) >= ?::timestamp)
                  AND (?::timestamp IS NULL OR a.public_start_at <= ?::timestamp)
                  {$involved}
                  {$placeFilter}
                ORDER BY a.public_start_at ASC NULLS LAST
                LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$statusCodes, $dateFrom, $dateFrom, $dateTo, $dateTo, ...$placeIds, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Returns actions for the print export: within [$dateFrom, $dateTo] (either bound null means
     * unbounded), matching one of $actionTypeIds — actions with no assigned type at all always
     * pass this filter too, since "no type" isn't something a type checklist can meaningfully
     * exclude. $actionTypeIds may be empty (user unchecked every type): then only actions with no
     * assigned type at all are returned.
     */
    /**
     * The generic export's row source. Each of $actionTypeIds/$visitorCategoryIds/$organizerIds is
     * an independent filter: an empty array means "no constraint from this filter" (matches every
     * action); a non-empty array means the action must carry at least one of those ids (OR within
     * the filter). All three filters that do have a selection are ANDed together — an action must
     * satisfy every active filter, matching at least one checked box from each. $statusIds works the
     * same way against the action's current status (empty = every status).
     */
    public function listForExport(array $actionTypeIds, array $visitorCategoryIds, array $organizerIds, ?string $dateFrom, ?string $dateTo, array $statusIds = []): array
    {
        $statusIds           = array_values(array_unique(array_map('intval', $statusIds)));
        $actionTypeIds       = array_values(array_unique(array_map('intval', $actionTypeIds)));
        $visitorCategoryIds  = array_values(array_unique(array_map('intval', $visitorCategoryIds)));
        $organizerIds        = array_values(array_unique(array_map('intval', $organizerIds)));

        $matchAnyOf = function (string $joinTable, string $idColumn, array $ids, string $extraCondition = ''): string {
            if (empty($ids)) {
                return '1=1';
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            return "EXISTS (SELECT 1 FROM {$joinTable} x WHERE x.action_id = a.id AND x.{$idColumn} IN ({$placeholders}){$extraCondition})";
        };

        $typeCondition     = $matchAnyOf('action_action_types', 'action_type_id', $actionTypeIds);
        $categoryCondition = $matchAnyOf('action_target_visitor_categories', 'visitor_category_id', $visitorCategoryIds);
        $organizerCondition = $matchAnyOf('action_organizers', 'user_id', $organizerIds, ' AND x.deactivated_at IS NULL');
        $statusCondition    = empty($statusIds)
            ? '1=1'
            : 'a.actual_status IN (' . implode(',', array_fill(0, count($statusIds), '?')) . ')';

        $sql = "SELECT a.id, a.name, a.public_start_at, a.public_end_at, a.staff_start_at, a.staff_end_at,
                       a.capacity, a.note, a.photograph, a.photograph_note, a.not_main_organizer,
                       s.code AS status_code, s.name AS status_name, e.name AS event_name
                FROM actions a
                JOIN action_statuses s ON s.id = a.actual_status
                JOIN events e          ON e.id = a.event_id
                WHERE (?::timestamp IS NULL OR COALESCE(a.public_end_at, a.public_start_at) >= ?::timestamp)
                  AND (?::timestamp IS NULL OR a.public_start_at <= ?::timestamp)
                  AND ({$typeCondition})
                  AND ({$categoryCondition})
                  AND ({$organizerCondition})
                  AND ({$statusCondition})
                ORDER BY a.public_start_at ASC NULLS LAST";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$dateFrom, $dateFrom, $dateTo, $dateTo, ...$actionTypeIds, ...$visitorCategoryIds, ...$organizerIds, ...$statusIds]);
        return array_map(function (array $row) {
            $row['photograph']         = self::normalizeBool($row['photograph']);
            $row['not_main_organizer'] = self::normalizeBool($row['not_main_organizer']);
            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * Normalizes a fetched BOOLEAN column to a native PHP bool. PDO_PGSQL's representation
     * depends on prepare mode: with PDO::ATTR_EMULATE_PREPARES disabled (this app's setting,
     * see Database::connection()) it already comes back as a native bool, but a plain truthy
     * check would still misfire on the 't'/'f' strings emulated prepares (or a different PDO
     * config) would produce instead — both are truthy in PHP regardless of the actual value.
     */
    private static function normalizeBool(mixed $value): bool
    {
        return $value === true || $value === 't';
    }

    /**
     * For the "Export pro mediální tým" report: all actions flagged photograph = TRUE (media team
     * must attend) whose public window overlaps [$dateFrom, $dateTo] — either bound may be null.
     * No status/type filtering, same convention as listForExport.
     */
    public function listForMediaTeamExport(?string $dateFrom, ?string $dateTo): array
    {
        $sql = "SELECT a.id, a.name, a.public_start_at, a.public_end_at,
                       a.staff_start_at, a.staff_end_at, a.photograph_note
                FROM actions a
                WHERE a.photograph = TRUE
                  AND (?::timestamp IS NULL OR COALESCE(a.public_end_at, a.public_start_at) >= ?::timestamp)
                  AND (?::timestamp IS NULL OR a.public_start_at <= ?::timestamp)
                ORDER BY a.public_start_at ASC NULLS LAST";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$dateFrom, $dateFrom, $dateTo, $dateTo]);
        return $stmt->fetchAll();
    }

    /**
     * For "Export pro údržbu": every action with at least one confirmed room, whose public
     * window overlaps [$dateFrom, $dateTo] — regardless of status (per explicit spec; a stale
     * confirmed-room row on a cancelled action is exactly what maintenance needs visibility
     * into, even though the auto-release-on-cancel/not-agreed behavior means this case should
     * now be rare).
     */
    public function listForMaintenanceExport(?string $dateFrom, ?string $dateTo): array
    {
        $sql = "SELECT DISTINCT a.id, a.name, a.staff_start_at, a.staff_end_at,
                       a.public_start_at, a.public_end_at, a.note
                FROM actions a
                WHERE EXISTS (SELECT 1 FROM action_places ap WHERE ap.action_id = a.id)
                  AND (?::timestamp IS NULL OR COALESCE(a.public_end_at, a.public_start_at) >= ?::timestamp)
                  AND (?::timestamp IS NULL OR a.public_start_at <= ?::timestamp)
                ORDER BY a.public_start_at ASC NULLS LAST";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$dateFrom, $dateFrom, $dateTo, $dateTo]);
        return $stmt->fetchAll();
    }

    /**
     * For "Plán akcí" (weekly plan): actions whose public window — or the staff window, when there
     * is no public one — overlaps [$dateFrom, $dateTo]. Cancelled and not-agreed actions are left
     * out: the plan shows what is actually going to happen.
     */
    public function listForWeeklyPlan(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT a.id, a.name, a.public_start_at, a.public_end_at, a.staff_start_at, a.staff_end_at
                FROM actions a
                JOIN action_statuses s ON s.id = a.actual_status
                WHERE s.code NOT IN ('cancelled', 'not_agreed')
                  AND COALESCE(a.public_end_at, a.public_start_at, a.staff_end_at, a.staff_start_at) >= ?::timestamp
                  AND COALESCE(a.public_start_at, a.staff_start_at) <= ?::timestamp
                ORDER BY COALESCE(a.public_start_at, a.staff_start_at) ASC, a.name ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$dateFrom, $dateTo]);
        return $stmt->fetchAll();
    }

    /** Returns all active action status codes/names, in workflow order. */
    public function listStatuses(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, code, name FROM action_statuses WHERE deactivated_at IS NULL ORDER BY id');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function addOrganizer(int $actionId, int $userId): void
    {
        $sql = <<<SQL
            INSERT INTO action_organizers (action_id, user_id)
            VALUES (:action_id, :user_id)
            ON CONFLICT DO NOTHING
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':user_id' => $userId]);
    }

    public function removeOrganizer(int $actionId, int $userId): void
    {
        $sql = <<<SQL
            UPDATE action_organizers
            SET deactivated_at = CURRENT_TIMESTAMP
            WHERE action_id = :action_id AND user_id = :user_id AND deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':user_id' => $userId]);
    }

    public function addSubstitute(int $actionId, int $userId): void
    {
        $sql = <<<SQL
            INSERT INTO action_substitutes (action_id, user_id)
            VALUES (:action_id, :user_id)
            ON CONFLICT DO NOTHING
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':user_id' => $userId]);
    }

    public function removeSubstitute(int $actionId, int $userId): void
    {
        $sql = <<<SQL
            UPDATE action_substitutes
            SET deactivated_at = CURRENT_TIMESTAMP
            WHERE action_id = :action_id AND user_id = :user_id AND deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':user_id' => $userId]);
    }

    public function addStaff(int $actionId, int $personId): void
    {
        $sql = <<<SQL
            INSERT INTO action_staff (action_id, person_id)
            VALUES (:action_id, :person_id)
            ON CONFLICT (action_id, person_id) DO UPDATE SET deactivated_at = NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':person_id' => $personId]);
    }

    public function removeStaff(int $actionId, int $personId): void
    {
        $sql = <<<SQL
            UPDATE action_staff
            SET deactivated_at = CURRENT_TIMESTAMP
            WHERE action_id = :action_id AND person_id = :person_id AND deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':person_id' => $personId]);
    }

    /** Replaces this action's set of organizers (by user_id) with $userIds. */
    public function syncOrganizers(int $actionId, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $current = array_map('intval', array_column($this->getOrganizers($actionId), 'user_id'));

        foreach (array_diff($current, $userIds) as $userId) {
            $this->removeOrganizer($actionId, $userId);
        }
        foreach (array_diff($userIds, $current) as $userId) {
            $this->addOrganizer($actionId, $userId);
        }
    }

    /** Replaces this action's set of substitutes (by user_id) with $userIds. */
    public function syncSubstitutes(int $actionId, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $current = array_map('intval', array_column($this->getSubstitutes($actionId), 'user_id'));

        foreach (array_diff($current, $userIds) as $userId) {
            $this->removeSubstitute($actionId, $userId);
        }
        foreach (array_diff($userIds, $current) as $userId) {
            $this->addSubstitute($actionId, $userId);
        }
    }

    /** Replaces this action's set of staff (by person_id) with $personIds. */
    public function syncStaff(int $actionId, array $personIds): void
    {
        $personIds = array_values(array_unique(array_map('intval', $personIds)));
        $current   = array_map('intval', array_column($this->getStaff($actionId), 'person_id'));

        foreach (array_diff($current, $personIds) as $personId) {
            $this->removeStaff($actionId, $personId);
        }
        foreach (array_diff($personIds, $current) as $personId) {
            $this->addStaff($actionId, $personId);
        }
    }

    public function getStaff(int $actionId): array
    {
        $sql = <<<SQL
            SELECT ast.person_id, p.full_name, p.short_name
            FROM action_staff ast
            JOIN persons p ON p.id = ast.person_id
            WHERE ast.action_id = :action_id AND ast.deactivated_at IS NULL
            ORDER BY p.full_name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    public function addPlace(int $actionId, int $placeId, ?string $note = null): void
    {
        $sql = <<<SQL
            INSERT INTO action_places (action_id, place_id, note)
            VALUES (:action_id, :place_id, :note)
            ON CONFLICT (action_id, place_id) DO UPDATE SET note = EXCLUDED.note
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':place_id' => $placeId, ':note' => $note]);
    }

    public function addPlaceDesire(int $actionId, int $placeId, ?string $note = null): void
    {
        $sql = <<<SQL
            INSERT INTO action_places_desire (action_id, place_id, note)
            VALUES (:action_id, :place_id, :note)
            ON CONFLICT (action_id, place_id) DO UPDATE SET note = EXCLUDED.note
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':place_id' => $placeId, ':note' => $note]);
    }

    /**
     * Replaces this action's set of room requests with $placeIds, preserving created_at of ones that stay
     * (ON CONFLICT DO UPDATE only touches note, not created_at). Rooms that get deselected also lose their
     * confirmed action_places assignment, if any — otherwise a removed room would stay silently booked with
     * no visible request left to manage it from.
     *
     * @param array<int, string|null> $notes place_id => note, applied to both new and already-existing requests
     */
    public function syncPlaceDesires(int $actionId, array $placeIds, array $notes = []): void
    {
        $placeIds = array_values(array_unique(array_map('intval', $placeIds)));

        // Phase 1: REMOVE — unchecked rooms that were previously requested lose their
        // request, and (if any) their confirmed action_places assignment too — otherwise
        // a removed room would stay silently booked with no visible request left to manage it from.
        if (empty($placeIds)) {
            $stmt = $this->pdo->prepare('DELETE FROM action_places_desire WHERE action_id = :action_id');
            $stmt->execute([':action_id' => $actionId]);
            $stmt = $this->pdo->prepare('DELETE FROM action_places WHERE action_id = :action_id');
            $stmt->execute([':action_id' => $actionId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($placeIds), '?'));
            $stmt = $this->pdo->prepare("DELETE FROM action_places_desire WHERE action_id = ? AND place_id NOT IN ({$placeholders})");
            $stmt->execute([$actionId, ...$placeIds]);

            $stmt = $this->pdo->prepare("DELETE FROM action_places WHERE action_id = ? AND place_id NOT IN ({$placeholders})");
            $stmt->execute([$actionId, ...$placeIds]);
        }

        // Phase 2: ADD/UPDATE — newly checked rooms not yet requested get inserted, existing
        // ones get their note refreshed (ON CONFLICT DO UPDATE preserves created_at / queue position).
        foreach ($placeIds as $placeId) {
            $this->addPlaceDesire($actionId, $placeId, $notes[$placeId] ?? null);
        }
    }

    public function removePlace(int $actionId, int $placeId): void
    {
        $sql = 'DELETE FROM action_places WHERE action_id = :action_id AND place_id = :place_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':place_id' => $placeId]);
    }

    /** Returns this action's room requests, each annotated with whether it's already confirmed into action_places. */
    public function getPlaceDesires(int $actionId): array
    {
        $sql = <<<SQL
            SELECT d.place_id, d.note, d.created_at, pl.name, pl.abbreviation,
                   EXISTS(
                       SELECT 1 FROM action_places ap
                       WHERE ap.action_id = d.action_id AND ap.place_id = d.place_id
                   ) AS confirmed
            FROM action_places_desire d
            JOIN places pl ON pl.id = d.place_id
            WHERE d.action_id = :action_id
            ORDER BY pl.name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    /** Releases (hard-deletes) every confirmed and requested room for an action — used when it's cancelled/marked not-agreed. */
    public function releaseAllPlaces(int $actionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM action_places_desire WHERE action_id = :action_id');
        $stmt->execute([':action_id' => $actionId]);
        $stmt = $this->pdo->prepare('DELETE FROM action_places WHERE action_id = :action_id');
        $stmt->execute([':action_id' => $actionId]);
    }

    /** True when this action has at least one confirmed room (an action_places row). */
    public function hasConfirmedPlace(int $actionId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM action_places WHERE action_id = :action_id LIMIT 1');
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchColumn() !== false;
    }

    /** True when this action has at least one attendance record. */
    public function hasAttendanceRecorded(int $actionId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM action_attendance WHERE action_id = :action_id LIMIT 1');
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchColumn() !== false;
    }

    /** True when this action has at least one income or cost row recorded. */
    public function hasEconomicDataRecorded(int $actionId): bool
    {
        $sql = <<<SQL
            SELECT EXISTS(SELECT 1 FROM action_incomes WHERE action_id = :income_action_id)
                OR EXISTS(SELECT 1 FROM action_costs   WHERE action_id = :cost_action_id)
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':income_action_id' => $actionId, ':cost_action_id' => $actionId]);
        return self::normalizeBool($stmt->fetchColumn());
    }

    /** Confirmed rooms for an action, with the room's own note and the action×room note — used by the maintenance export. */
    public function getConfirmedPlacesWithNotes(int $actionId): array
    {
        $sql = <<<SQL
            SELECT p.name, p.note AS room_note, ap.note AS action_place_note
            FROM action_places ap
            JOIN places p ON p.id = ap.place_id
            WHERE ap.action_id = :action_id
            ORDER BY p.name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    /**
     * Counts still-relevant requests for the same room, made earlier than this action's own
     * request, whose time window collides with it — i.e. how many competitors stand ahead of
     * this action in the room's queue.
     *
     * Two actions collide over a room unless one's public end is at or before the other's staff
     * start (staff need the room from staff_start_at, e.g. to prepare it, so a gap is only real
     * if it exists on the staff side): NOT ( a1.public_end_at <= a2.staff_start_at
     *                                     OR a2.public_end_at <= a1.staff_start_at ).
     * An unprovable (NULL) comparison never counts as a proof of "no collision" (COALESCE ... FALSE),
     * preserving the previous conservative-NULL behavior.
     */
    private function earlierCompetingRequestsCount(int $actionId, int $placeId): int
    {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM action_places_desire d
            JOIN actions a2         ON a2.id = d.action_id
            JOIN action_statuses s2 ON s2.id = a2.actual_status
            JOIN actions a1         ON a1.id = :action_id
            JOIN action_places_desire d1
                 ON d1.action_id = a1.id AND d1.place_id = :place_id
            WHERE d.place_id = :place_id
              AND d.action_id != :action_id
              AND s2.code NOT IN ('not_agreed', 'cancelled')
              AND d.created_at < d1.created_at
              AND NOT (
                    COALESCE(a1.public_end_at <= a2.staff_start_at, FALSE)
                 OR COALESCE(a2.public_end_at <= a1.staff_start_at, FALSE)
              )
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':place_id' => $placeId]);
        return (int) $stmt->fetchColumn();
    }

    /** True when no earlier still-relevant request for the same room collides — i.e. this action is first in the room's queue. */
    public function canConfirmPlaceDesire(int $actionId, int $placeId): bool
    {
        return $this->earlierCompetingRequestsCount($actionId, $placeId) === 0;
    }

    /** This action's 1-based position in the room's queue (1 = first in line, matches canConfirmPlaceDesire()). */
    public function queuePosition(int $actionId, int $placeId): int
    {
        return $this->earlierCompetingRequestsCount($actionId, $placeId) + 1;
    }

    /**
     * Whether a brand-new request for this room, made right now by this action (no
     * action_places_desire row exists yet), would be immediately confirmable. Since a new row's
     * created_at is later than any existing one's, this collapses to "no other still-relevant
     * overlapping request exists at all" — same collision rule as earlierCompetingRequestsCount,
     * just without needing this action's own (not yet created) desire row for the created_at compare.
     */
    public function canConfirmNewRequest(int $actionId, int $placeId): bool
    {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM action_places_desire d
            JOIN actions a2         ON a2.id = d.action_id
            JOIN action_statuses s2 ON s2.id = a2.actual_status
            JOIN actions a1         ON a1.id = :action_id
            WHERE d.place_id = :place_id
              AND d.action_id != :action_id
              AND s2.code NOT IN ('not_agreed', 'cancelled')
              AND NOT (
                    COALESCE(a1.public_end_at <= a2.staff_start_at, FALSE)
                 OR COALESCE(a2.public_end_at <= a1.staff_start_at, FALSE)
              )
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':place_id' => $placeId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /**
     * Confirms a room request into action_places if this action is first in the room's queue,
     * carrying over the request's note. Returns false otherwise. The request itself is left
     * untouched — it stays "requested" even once confirmed, and must be cancelled separately.
     */
    public function confirmPlaceDesire(int $actionId, int $placeId): bool
    {
        if (!$this->canConfirmPlaceDesire($actionId, $placeId)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT note FROM action_places_desire WHERE action_id = :action_id AND place_id = :place_id'
        );
        $stmt->execute([':action_id' => $actionId, ':place_id' => $placeId]);
        $note = $stmt->fetchColumn();

        $this->addPlace($actionId, $placeId, $note !== false ? $note : null);
        return true;
    }

    /**
     * Syncs which of this action's existing room requests are confirmed. A newly-included place
     * is confirmed only if this action is still first in that room's queue (server-side re-check —
     * the edit form disabling that choice client-side is not trusted); a newly-excluded place that
     * was confirmed has its action_places assignment cancelled. Ids not currently requested are
     * ignored — this only ever toggles confirmation of an existing request, never creates one
     * (that's syncPlaceDesires's job — see syncPlaceStates(), which runs it first).
     *
     * A place that's already confirmed and stays selected as confirmed is still re-checked against
     * canConfirmPlaceDesire(): editPost() writes the action's (possibly changed) dates before
     * calling this, so a room that was confirmed under the old time window can turn out to no
     * longer be free (or no longer first in the queue) under the new one. That's downgraded back
     * to merely queued — removePlace() only ever cancels the confirmation, never the underlying
     * request — instead of silently leaving a stale confirmation that no longer reflects reality.
     *
     * Also true for its *note*: confirming only copies the request's note across once, at the
     * moment of confirmation (see confirmPlaceDesire()). If the room stays confirmed across this
     * call but its request note was edited since, that edit would otherwise never reach the
     * confirmed action_places row — which is what the maintenance export actually reads — so it
     * gets re-synced here too via addPlace()'s upsert.
     */
    public function syncPlaceConfirmations(int $actionId, array $confirmPlaceIds): void
    {
        $confirmPlaceIds = array_map('intval', $confirmPlaceIds);

        foreach ($this->getPlaceDesires($actionId) as $desire) {
            $placeId           = (int) $desire['place_id'];
            $shouldBeConfirmed = in_array($placeId, $confirmPlaceIds, true);

            if ($shouldBeConfirmed && !$desire['confirmed']) {
                $this->confirmPlaceDesire($actionId, $placeId);
            } elseif (!$shouldBeConfirmed && $desire['confirmed']) {
                $this->removePlace($actionId, $placeId);
            } elseif ($shouldBeConfirmed && $desire['confirmed']) {
                if (!$this->canConfirmPlaceDesire($actionId, $placeId)) {
                    $this->removePlace($actionId, $placeId);
                } else {
                    $this->addPlace($actionId, $placeId, $desire['note']);
                }
            }
        }
    }

    /**
     * Applies the edit form's per-room tri-state picker (none / requested / confirmed) in one
     * call: places in 'requested' or 'confirmed' state should have a request at all, and of
     * those, only 'confirmed' ones should also be confirmed. Delegates to the two existing sync
     * methods in that order, so dropping a request also cascades to drop its confirmation.
     *
     * @param array<int|string, string> $placeStates place_id => 'none'|'requested'|'confirmed'
     * @param array<int, string|null> $notes place_id => note, carried into the room request
     */
    public function syncPlaceStates(int $actionId, array $placeStates, array $notes = []): void
    {
        $requestedIds = [];
        $confirmedIds = [];
        foreach ($placeStates as $placeId => $state) {
            if ($state === 'requested' || $state === 'confirmed') {
                $requestedIds[] = (int) $placeId;
            }
            if ($state === 'confirmed') {
                $confirmedIds[] = (int) $placeId;
            }
        }

        $this->syncPlaceDesires($actionId, $requestedIds, $notes);
        $this->syncPlaceConfirmations($actionId, $confirmedIds);
    }

    /** Returns this action's assigned action type ids. */
    public function getActionTypeIds(int $actionId): array
    {
        $stmt = $this->pdo->prepare('SELECT action_type_id FROM action_action_types WHERE action_id = :action_id');
        $stmt->execute([':action_id' => $actionId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Returns this action's assigned action types (id/name/abbreviation), name-sorted. */
    public function getActionTypes(int $actionId): array
    {
        $sql = <<<SQL
            SELECT t.id, t.name, t.abbreviation
            FROM action_action_types aat
            JOIN action_types t ON t.id = aat.action_type_id
            WHERE aat.action_id = :action_id
            ORDER BY t.name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    /** Replaces this action's set of action types with $typeIds. */
    public function syncActionTypes(int $actionId, array $typeIds): void
    {
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));

        $stmt = $this->pdo->prepare('DELETE FROM action_action_types WHERE action_id = :action_id');
        $stmt->execute([':action_id' => $actionId]);

        foreach ($typeIds as $typeId) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO action_action_types (action_id, action_type_id) VALUES (:action_id, :action_type_id)'
            );
            $stmt->execute([':action_id' => $actionId, ':action_type_id' => $typeId]);
        }
    }

    /** Returns this action's assigned action topic ids. */
    public function getActionTopicIds(int $actionId): array
    {
        $stmt = $this->pdo->prepare('SELECT action_topic_id FROM action_action_topics WHERE action_id = :action_id');
        $stmt->execute([':action_id' => $actionId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Returns this action's assigned action topics (id/name/abbreviation), name-sorted. */
    public function getActionTopics(int $actionId): array
    {
        $sql = <<<SQL
            SELECT t.id, t.name, t.abbreviation
            FROM action_action_topics aat
            JOIN action_topics t ON t.id = aat.action_topic_id
            WHERE aat.action_id = :action_id
            ORDER BY t.name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    /** Replaces this action's set of action topics with $topicIds. */
    public function syncActionTopics(int $actionId, array $topicIds): void
    {
        $topicIds = array_values(array_unique(array_map('intval', $topicIds)));

        $stmt = $this->pdo->prepare('DELETE FROM action_action_topics WHERE action_id = :action_id');
        $stmt->execute([':action_id' => $actionId]);

        foreach ($topicIds as $topicId) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO action_action_topics (action_id, action_topic_id) VALUES (:action_id, :action_topic_id)'
            );
            $stmt->execute([':action_id' => $actionId, ':action_topic_id' => $topicId]);
        }
    }

    /** Returns this action's assigned action format ids. */
    public function getActionFormatIds(int $actionId): array
    {
        $stmt = $this->pdo->prepare('SELECT action_format_id FROM action_action_formats WHERE action_id = :action_id');
        $stmt->execute([':action_id' => $actionId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Returns this action's assigned action formats (id/name/abbreviation), name-sorted. */
    public function getActionFormats(int $actionId): array
    {
        $sql = <<<SQL
            SELECT t.id, t.name, t.abbreviation
            FROM action_action_formats aaf
            JOIN action_formats t ON t.id = aaf.action_format_id
            WHERE aaf.action_id = :action_id
            ORDER BY t.name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    /** Replaces this action's set of action formats with $formatIds. */
    public function syncActionFormats(int $actionId, array $formatIds): void
    {
        $formatIds = array_values(array_unique(array_map('intval', $formatIds)));

        $stmt = $this->pdo->prepare('DELETE FROM action_action_formats WHERE action_id = :action_id');
        $stmt->execute([':action_id' => $actionId]);

        foreach ($formatIds as $formatId) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO action_action_formats (action_id, action_format_id) VALUES (:action_id, :action_format_id)'
            );
            $stmt->execute([':action_id' => $actionId, ':action_format_id' => $formatId]);
        }
    }

    /** Returns this action's targeted visitor category ids. */
    public function getTargetVisitorCategoryIds(int $actionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT visitor_category_id FROM action_target_visitor_categories WHERE action_id = :action_id'
        );
        $stmt->execute([':action_id' => $actionId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Returns this action's targeted visitor categories (id/name), name-sorted. */
    public function getTargetVisitorCategories(int $actionId): array
    {
        $sql = <<<SQL
            SELECT c.id, c.name
            FROM action_target_visitor_categories atvc
            JOIN visitor_categories c ON c.id = atvc.visitor_category_id
            WHERE atvc.action_id = :action_id
            ORDER BY c.name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    /** Replaces this action's set of targeted visitor categories with $categoryIds. */
    public function syncTargetVisitorCategories(int $actionId, array $categoryIds): void
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));

        $stmt = $this->pdo->prepare('DELETE FROM action_target_visitor_categories WHERE action_id = :action_id');
        $stmt->execute([':action_id' => $actionId]);

        foreach ($categoryIds as $categoryId) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO action_target_visitor_categories (action_id, visitor_category_id) VALUES (:action_id, :visitor_category_id)'
            );
            $stmt->execute([':action_id' => $actionId, ':visitor_category_id' => $categoryId]);
        }
    }

    /** Returns this action's recorded attendance rows (visitor type × category counts), name-sorted. */
    public function getAttendance(int $actionId): array
    {
        $sql = <<<SQL
            SELECT a.id, a.visitor_type_id, vt.name AS visitor_type_name,
                   a.visitor_category_id, vc.name AS visitor_category_name,
                   a.visitor_count, a.note
            FROM action_attendance a
            JOIN visitor_types vt      ON vt.id = a.visitor_type_id
            JOIN visitor_categories vc ON vc.id = a.visitor_category_id
            WHERE a.action_id = :action_id
            ORDER BY vt.name ASC, vc.name ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    /** Sums attendance across every action in an event, broken down by visitor category. */
    public function eventAttendanceTotals(int $eventId): array
    {
        $sql = <<<SQL
            SELECT vc.id, vc.name, SUM(aa.visitor_count) AS total
            FROM action_attendance aa
            JOIN actions a             ON a.id = aa.action_id
            JOIN visitor_categories vc ON vc.id = aa.visitor_category_id
            WHERE a.event_id = :event_id
            GROUP BY vc.id, vc.name
            ORDER BY vc.name
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':event_id' => $eventId]);
        return $stmt->fetchAll();
    }

    /** Records (or updates, if the same type+category combo already exists) an attendance count. */
    public function addAttendance(int $actionId, int $visitorTypeId, int $visitorCategoryId, int $visitorCount, ?string $note = null): void
    {
        $sql = <<<SQL
            INSERT INTO action_attendance (action_id, visitor_type_id, visitor_category_id, visitor_count, note)
            VALUES (:action_id, :visitor_type_id, :visitor_category_id, :visitor_count, :note)
            ON CONFLICT (action_id, visitor_type_id, visitor_category_id)
            DO UPDATE SET visitor_count = EXCLUDED.visitor_count, note = EXCLUDED.note
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':action_id'           => $actionId,
            ':visitor_type_id'     => $visitorTypeId,
            ':visitor_category_id' => $visitorCategoryId,
            ':visitor_count'       => $visitorCount,
            ':note'                => $note,
        ]);
    }

    public function removeAttendance(int $actionId, int $attendanceId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM action_attendance WHERE id = :id AND action_id = :action_id');
        $stmt->execute([':id' => $attendanceId, ':action_id' => $actionId]);
    }

    public function getStatusHistory(int $actionId): array
    {
        $sql = <<<SQL
            SELECT ash.*, s.name AS status_name, s.code AS status_code,
                   p.full_name AS user_name
            FROM action_status_history ash
            JOIN action_statuses s ON s.id = ash.action_status_id
            LEFT JOIN app_users au ON au.id = ash.created_by_user_id
            LEFT JOIN persons p    ON p.id  = au.person_id
            WHERE ash.action_id = :action_id
            ORDER BY ash.created_at DESC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    public function getOrganizers(int $actionId): array
    {
        $sql = <<<SQL
            SELECT ao.user_id, p.full_name, p.short_name
            FROM action_organizers ao
            JOIN app_users au ON au.id = ao.user_id
            LEFT JOIN persons p ON p.id = au.person_id
            WHERE ao.action_id = :action_id AND ao.deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    public function getSubstitutes(int $actionId): array
    {
        $sql = <<<SQL
            SELECT as2.user_id, p.full_name, p.short_name
            FROM action_substitutes as2
            JOIN app_users au ON au.id = as2.user_id
            LEFT JOIN persons p ON p.id = au.person_id
            WHERE as2.action_id = :action_id AND as2.deactivated_at IS NULL
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    public function getDraftStatusId(): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM action_statuses WHERE code = 'draft'");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function recordInitialStatus(int $actionId, int $statusId, int $userId): void
    {
        $sql = <<<SQL
            INSERT INTO action_status_history (action_id, action_status_id, created_by_user_id, note)
            VALUES (:action_id, :status_id, :user_id, 'Akce vytvořena')
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':status_id' => $statusId, ':user_id' => $userId]);
    }

    /** True when $userId is an active organizer of this action. */
    public function isOrganizer(int $actionId, int $userId): bool
    {
        $sql = <<<SQL
            SELECT 1 FROM action_organizers
            WHERE action_id = :action_id AND user_id = :user_id AND deactivated_at IS NULL
            LIMIT 1
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId, ':user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Permanently deletes an action; related rows are removed via ON DELETE CASCADE. */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM actions WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
