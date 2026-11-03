<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use InvalidArgumentException;
use PDO;

/**
 * Aggregation queries backing the "Specifické exporty" reports on the Export page (currently just
 * "Statistika") — kept separate from ActionRepository/FinanceRepository since these queries
 * are report-specific bucketing, not general-purpose action data access.
 *
 * Every method filters actions the same way: public_start_at within [from, toInclusive 23:59:59],
 * status not in ('not_agreed', 'cancelled') — same convention as ActionRepository::listInRange()
 * and ExportController::listForExport().
 *
 * Every method taking $typeNames returns null instead of a (possibly silently-wrong) 0 when NONE
 * of the given names exist in action_types at all — real dictionaries drift from whatever fixed
 * names a report expects (e.g. a typo, or a differently-worded type), and a wrong zero in an
 * official statistics export is worse than an obvious blank. An empty $typeNames array is exempt
 * from this check — it deliberately means "any action, no Type filter" (grand-total rows).
 * Methods that also filter by a Téma (topic) or Provedení (format) name apply the same rule to
 * that lookup.
 *
 * All queries use positional (?) placeholders throughout — PDO does not allow mixing positional
 * and named placeholders in one prepared statement.
 */
final class StatisticsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function countActionsByTypes(array $typeNames, string $from, string $toInclusive): ?int
    {
        if (!$this->anyTypeExists($typeNames)) {
            return null;
        }
        [$sql, $params] = $this->build('COUNT(DISTINCT a.id)', $typeNames, $from, $toInclusive);
        return (int) $this->run($sql, $params);
    }

    public function countActionsByTypesAndFormat(array $typeNames, string $formatName, string $from, string $toInclusive): ?int
    {
        if (!$this->anyTypeExists($typeNames) || !$this->exists('action_formats', $formatName)) {
            return null;
        }
        [$sql, $params] = $this->build('COUNT(DISTINCT a.id)', $typeNames, $from, $toInclusive, formatName: $formatName);
        return (int) $this->run($sql, $params);
    }

    public function countActionsByTypesAndTopic(array $typeNames, string $topicName, string $from, string $toInclusive): ?int
    {
        if (!$this->anyTypeExists($typeNames) || !$this->exists('action_topics', $topicName)) {
            return null;
        }
        [$sql, $params] = $this->build('COUNT(DISTINCT a.id)', $typeNames, $from, $toInclusive, topicName: $topicName);
        return (int) $this->run($sql, $params);
    }

    /** @return ?int seconds */
    public function sumPublicSecondsByTypes(array $typeNames, string $from, string $toInclusive): ?int
    {
        if (!$this->anyTypeExists($typeNames)) {
            return null;
        }
        [$sql, $params] = $this->build($this->durationSumExpr(), $typeNames, $from, $toInclusive);
        return (int) round((float) $this->run($sql, $params));
    }

    /** @return ?int seconds, or null when the named format doesn't exist */
    public function sumPublicSecondsByTypesAndFormat(array $typeNames, string $formatName, string $from, string $toInclusive): ?int
    {
        if (!$this->anyTypeExists($typeNames) || !$this->exists('action_formats', $formatName)) {
            return null;
        }
        [$sql, $params] = $this->build($this->durationSumExpr(), $typeNames, $from, $toInclusive, formatName: $formatName);
        return (int) round((float) $this->run($sql, $params));
    }

    /** @return ?int seconds, or null when the named topic doesn't exist */
    public function sumPublicSecondsByTypesAndTopic(array $typeNames, string $topicName, string $from, string $toInclusive): ?int
    {
        if (!$this->anyTypeExists($typeNames) || !$this->exists('action_topics', $topicName)) {
            return null;
        }
        [$sql, $params] = $this->build($this->durationSumExpr(), $typeNames, $from, $toInclusive, topicName: $topicName);
        return (int) round((float) $this->run($sql, $params));
    }

    /** $typeNames may be empty — an empty array means "any action, regardless of Type" (used for the grand-total row). */
    public function sumAttendanceByTypes(array $typeNames, string $from, string $toInclusive, ?string $visitorTypeName = null): ?int
    {
        if (!$this->anyTypeExists($typeNames)) {
            return null;
        }
        if ($visitorTypeName !== null && !$this->exists('visitor_types', $visitorTypeName)) {
            return null;
        }
        [$sql, $params] = $this->build(
            'COALESCE(SUM(aa.visitor_count), 0)',
            $typeNames,
            $from,
            $toInclusive,
            joinAttendance: true,
            visitorTypeName: $visitorTypeName
        );
        return (int) $this->run($sql, $params);
    }

    /**
     * Attendance sum for actions carrying at least one of $typeNames (the action may carry other
     * Types too — it just must include one of these), filtered to one named visitor Kategorie
     * (e.g. "Žáci 1. st. ZŠ"). Null when $typeNames has no active match, or the named category
     * doesn't exist as an active entry — same "missing dictionary config" convention as elsewhere.
     */
    public function sumAttendanceByTypesAndVisitorCategory(array $typeNames, string $categoryName, string $from, string $toInclusive): ?int
    {
        if (!$this->anyTypeExists($typeNames) || !$this->exists('visitor_categories', $categoryName)) {
            return null;
        }
        [$sql, $params] = $this->build(
            'COALESCE(SUM(aa.visitor_count), 0)',
            $typeNames,
            $from,
            $toInclusive,
            joinAttendance: true,
            visitorCategoryName: $categoryName
        );
        return (int) $this->run($sql, $params);
    }

    /** Sums CZK "Vstupné" income for actions matching $typeNames. Other currencies are excluded rather than mixed into one total. */
    public function sumIncomeVstupneByTypes(array $typeNames, string $from, string $toInclusive): ?float
    {
        if (!$this->anyTypeExists($typeNames)) {
            return null;
        }
        [$sql, $params] = $this->build('COALESCE(SUM(ai.amount), 0)', $typeNames, $from, $toInclusive, joinVstupneIncome: true);
        return (float) $this->run($sql, $params);
    }

    /**
     * Count of actions flagged "Nejsme hlavní pořadatel" (actions.not_main_organizer). Unlike the
     * Type/Topic/Format lookups above, this is a plain boolean column, not a named dictionary entry
     * that can go missing or drift — so a genuine 0 is always a real answer, never "not configured".
     */
    public function countActionsByNotMainOrganizer(string $from, string $toInclusive): int
    {
        $sql = <<<SQL
            SELECT COUNT(DISTINCT a.id)
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            WHERE a.public_start_at >= ?
              AND a.public_start_at <= ?
              AND s.code NOT IN ('not_agreed', 'cancelled')
              AND a.not_main_organizer = TRUE
        SQL;
        return (int) $this->run($sql, [$from, $toInclusive . ' 23:59:59']);
    }

    /** Attendance sum for actions flagged "Nejsme hlavní pořadatel" — see countActionsByNotMainOrganizer(). */
    public function sumAttendanceByNotMainOrganizer(string $from, string $toInclusive): int
    {
        $sql = <<<SQL
            SELECT COALESCE(SUM(aa.visitor_count), 0)
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            LEFT JOIN action_attendance aa ON aa.action_id = a.id
            WHERE a.public_start_at >= ?
              AND a.public_start_at <= ?
              AND s.code NOT IN ('not_agreed', 'cancelled')
              AND a.not_main_organizer = TRUE
        SQL;
        return (int) $this->run($sql, [$from, $toInclusive . ' 23:59:59']);
    }

    /**
     * Sums CZK costs whose "za co" (cost_objects) entry matches $objectName — e.g. "Propagace" for
     * promotion spend. Null when that name isn't an active cost_objects entry, same "missing
     * dictionary config" convention as the Type/Topic/Format lookups above.
     */
    public function sumCostByObjectName(string $objectName, string $from, string $toInclusive): ?float
    {
        if (!$this->exists('cost_objects', $objectName)) {
            return null;
        }
        $sql = <<<SQL
            SELECT COALESCE(SUM(ac.amount), 0)
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            JOIN action_costs ac   ON ac.action_id = a.id
            JOIN cost_objects co   ON co.id = ac.cost_object_id AND co.name = ?
            JOIN currencies cur    ON cur.id = ac.currency_id AND cur.code = 'CZK'
            WHERE a.public_start_at >= ?
              AND a.public_start_at <= ?
              AND s.code NOT IN ('not_agreed', 'cancelled')
        SQL;
        return (float) $this->run($sql, [$objectName, $from, $toInclusive . ' 23:59:59']);
    }

    /** True when $typeNames is empty (no filter requested) or at least one of the names is a real, active action_types row. */
    private function anyTypeExists(array $typeNames): bool
    {
        if (empty($typeNames)) {
            return true;
        }
        $placeholders = implode(',', array_fill(0, count($typeNames), '?'));
        $stmt = $this->pdo->prepare("SELECT 1 FROM action_types WHERE name IN ({$placeholders}) AND deactivated_at IS NULL LIMIT 1");
        $stmt->execute($typeNames);
        return $stmt->fetchColumn() !== false;
    }

    private function exists(string $table, string $name): bool
    {
        if (!in_array($table, ['action_formats', 'action_topics', 'visitor_types', 'visitor_categories', 'cost_objects'], true)) {
            throw new InvalidArgumentException("Unexpected dictionary table: {$table}");
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE name = ? AND deactivated_at IS NULL LIMIT 1");
        $stmt->execute([$name]);
        return $stmt->fetchColumn() !== false;
    }

    /** EXTRACT(EPOCH FROM ...) sums public_end_at - public_start_at in seconds, skipping rows where either is NULL. */
    private function durationSumExpr(): string
    {
        return 'COALESCE(SUM(EXTRACT(EPOCH FROM (a.public_end_at - a.public_start_at))), 0)';
    }

    /**
     * Builds one query + its ordered param list. $typeNames narrows to actions carrying at least
     * one of these Types (skipped entirely when empty — see class doc); the optional filters
     * narrow it further to a single named Format, a single named Topic, a single named visitor
     * Type or visitor Kategorie (for attendance), or Vstupné income joined in CZK only.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function build(
        string $selectExpr,
        array $typeNames,
        string $from,
        string $toInclusive,
        ?string $formatName = null,
        ?string $topicName = null,
        bool $joinAttendance = false,
        ?string $visitorTypeName = null,
        ?string $visitorCategoryName = null,
        bool $joinVstupneIncome = false,
    ): array {
        $typePlaceholders = implode(',', array_fill(0, count($typeNames), '?'));
        $params = [$from, $toInclusive . ' 23:59:59', ...$typeNames];
        $typeFilter = empty($typeNames) ? '' : <<<SQL
              AND EXISTS (
                    SELECT 1 FROM action_action_types aat2
                    JOIN action_types t2 ON t2.id = aat2.action_type_id
                    WHERE aat2.action_id = a.id AND t2.name IN ({$typePlaceholders})
              )
            SQL;

        $joins = '';
        if ($joinAttendance) {
            $joins .= ' LEFT JOIN action_attendance aa   ON aa.action_id = a.id
                        LEFT JOIN visitor_types vt        ON vt.id = aa.visitor_type_id
                        LEFT JOIN visitor_categories vc   ON vc.id = aa.visitor_category_id';
        }
        if ($joinVstupneIncome) {
            $joins .= " LEFT JOIN action_incomes ai ON ai.action_id = a.id
                        LEFT JOIN income_types it     ON it.id = ai.income_type_id AND it.name = 'Vstupné'
                        LEFT JOIN currencies cur       ON cur.id = ai.currency_id AND cur.code = 'CZK'";
        }

        $extraWhere = '';
        if ($formatName !== null) {
            $extraWhere .= ' AND EXISTS (SELECT 1 FROM action_action_formats aaf JOIN action_formats f ON f.id = aaf.action_format_id
                                          WHERE aaf.action_id = a.id AND f.name = ?)';
            $params[] = $formatName;
        }
        if ($topicName !== null) {
            $extraWhere .= ' AND EXISTS (SELECT 1 FROM action_action_topics aat JOIN action_topics t ON t.id = aat.action_topic_id
                                          WHERE aat.action_id = a.id AND t.name = ?)';
            $params[] = $topicName;
        }
        if ($visitorTypeName !== null) {
            $extraWhere .= ' AND vt.name = ?';
            $params[] = $visitorTypeName;
        }
        if ($visitorCategoryName !== null) {
            $extraWhere .= ' AND vc.name = ?';
            $params[] = $visitorCategoryName;
        }
        if ($joinVstupneIncome) {
            $extraWhere .= ' AND ai.id IS NOT NULL AND it.id IS NOT NULL AND cur.id IS NOT NULL';
        }

        $sql = <<<SQL
            SELECT {$selectExpr}
            FROM actions a
            JOIN action_statuses s ON s.id = a.actual_status
            {$joins}
            WHERE a.public_start_at >= ?
              AND a.public_start_at <= ?
              AND s.code NOT IN ('not_agreed', 'cancelled')
              {$typeFilter}
              {$extraWhere}
        SQL;

        return [$sql, $params];
    }

    private function run(string $sql, array $params): string|int|float|null
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
