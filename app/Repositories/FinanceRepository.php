<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class FinanceRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function listIncomes(int $actionId): array
    {
        $sql = <<<SQL
            SELECT ai.id, ai.amount, ai.reference_number, ai.note,
                   ai.income_type_id, ai.income_subtype_id, ai.currency_id,
                   it.name  AS type_name,
                   ist.name AS subtype_name,
                   c.code   AS currency_code, c.symbol AS currency_symbol
            FROM action_incomes ai
            JOIN income_types it        ON it.id = ai.income_type_id
            LEFT JOIN income_subtypes ist ON ist.id = ai.income_subtype_id
            JOIN currencies c           ON c.id = ai.currency_id
            WHERE ai.action_id = :action_id
            ORDER BY ai.id ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    public function listCosts(int $actionId): array
    {
        $sql = <<<SQL
            SELECT ac.id, ac.amount, ac.reference_number, ac.note,
                   ac.cost_type_id, ac.cost_object_id, ac.currency_id,
                   ct.name AS type_name,
                   co.name AS object_name,
                   c.code  AS currency_code, c.symbol AS currency_symbol
            FROM action_costs ac
            JOIN cost_types ct   ON ct.id = ac.cost_type_id
            JOIN cost_objects co ON co.id = ac.cost_object_id
            JOIN currencies c    ON c.id = ac.currency_id
            WHERE ac.action_id = :action_id
            ORDER BY ac.id ASC
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    /** Sums incomes/costs per currency (multiple currencies are never mixed into one total). */
    public function incomeTotals(int $actionId): array
    {
        return $this->totals('action_incomes', 'ai', $actionId);
    }

    public function costTotals(int $actionId): array
    {
        return $this->totals('action_costs', 'ac', $actionId);
    }

    private function totals(string $table, string $alias, int $actionId): array
    {
        $sql = <<<SQL
            SELECT c.code, c.symbol, SUM({$alias}.amount) AS total
            FROM {$table} {$alias}
            JOIN currencies c ON c.id = {$alias}.currency_id
            WHERE {$alias}.action_id = :action_id
            GROUP BY c.code, c.symbol
            ORDER BY c.code
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':action_id' => $actionId]);
        return $stmt->fetchAll();
    }

    /** Sums incomes across every action in an event, broken down by income type and currency. */
    public function eventIncomeTotalsByType(int $eventId): array
    {
        return $this->eventTotalsByType('action_incomes', 'ai', 'income_types', 'it', 'income_type_id', $eventId);
    }

    /** Sums costs across every action in an event, broken down by cost type and currency. */
    public function eventCostTotalsByType(int $eventId): array
    {
        return $this->eventTotalsByType('action_costs', 'ac', 'cost_types', 'ct', 'cost_type_id', $eventId);
    }

    private function eventTotalsByType(string $table, string $alias, string $typeTable, string $typeAlias, string $typeColumn, int $eventId): array
    {
        $sql = <<<SQL
            SELECT {$typeAlias}.name AS type_name, c.code, c.symbol, SUM({$alias}.amount) AS total
            FROM {$table} {$alias}
            JOIN actions a            ON a.id = {$alias}.action_id
            JOIN {$typeTable} {$typeAlias} ON {$typeAlias}.id = {$alias}.{$typeColumn}
            JOIN currencies c         ON c.id = {$alias}.currency_id
            WHERE a.event_id = :event_id
            GROUP BY {$typeAlias}.name, c.code, c.symbol
            ORDER BY {$typeAlias}.name, c.code
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':event_id' => $eventId]);
        return $stmt->fetchAll();
    }

    /** Sums incomes across every action in an event, per currency (no type breakdown). */
    public function eventIncomeGrandTotals(int $eventId): array
    {
        return $this->eventGrandTotals('action_incomes', 'ai', $eventId);
    }

    /** Sums costs across every action in an event, per currency (no type breakdown). */
    public function eventCostGrandTotals(int $eventId): array
    {
        return $this->eventGrandTotals('action_costs', 'ac', $eventId);
    }

    private function eventGrandTotals(string $table, string $alias, int $eventId): array
    {
        $sql = <<<SQL
            SELECT c.code, c.symbol, SUM({$alias}.amount) AS total
            FROM {$table} {$alias}
            JOIN actions a    ON a.id = {$alias}.action_id
            JOIN currencies c ON c.id = {$alias}.currency_id
            WHERE a.event_id = :event_id
            GROUP BY c.code, c.symbol
            ORDER BY c.code
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':event_id' => $eventId]);
        return $stmt->fetchAll();
    }

    public function incomeTypes(): array
    {
        $stmt = $this->pdo->query("SELECT id, name FROM income_types WHERE deactivated_at IS NULL ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    /** Returns all active subtypes; each row carries its income_type_id for client-side filtering. */
    public function incomeSubtypes(): array
    {
        $stmt = $this->pdo->query("SELECT id, income_type_id, name FROM income_subtypes WHERE deactivated_at IS NULL ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    public function costTypes(): array
    {
        $stmt = $this->pdo->query("SELECT id, name FROM cost_types WHERE deactivated_at IS NULL ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    /** "Za co" — independent of cost_types (no parent/child relationship, unlike income_subtypes). */
    public function costObjects(): array
    {
        $stmt = $this->pdo->query("SELECT id, name FROM cost_objects WHERE deactivated_at IS NULL ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    public function currencies(): array
    {
        $stmt = $this->pdo->query("SELECT id, code, name, symbol FROM currencies WHERE deactivated_at IS NULL ORDER BY code ASC");
        return $stmt->fetchAll();
    }

    /** Replaces the full set of income rows for an action (delete + re-insert). */
    public function replaceIncomes(int $actionId, array $rows): void
    {
        $this->pdo->prepare('DELETE FROM action_incomes WHERE action_id = :action_id')
            ->execute([':action_id' => $actionId]);

        if (empty($rows)) {
            return;
        }

        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO action_incomes
                (action_id, income_type_id, income_subtype_id, amount, currency_id, reference_number, note)
            VALUES
                (:action_id, :type_id, :subtype_id, :amount, :currency_id, :reference_number, :note)
        SQL);
        foreach ($rows as $row) {
            $stmt->execute([
                ':action_id'        => $actionId,
                ':type_id'          => $row['type_id'],
                ':subtype_id'       => $row['subtype_id'],
                ':amount'           => $row['amount'],
                ':currency_id'      => $row['currency_id'],
                ':reference_number' => $row['reference_number'],
                ':note'             => $row['note'],
            ]);
        }
    }

    /** Replaces the full set of cost rows for an action (delete + re-insert). */
    public function replaceCosts(int $actionId, array $rows): void
    {
        $this->pdo->prepare('DELETE FROM action_costs WHERE action_id = :action_id')
            ->execute([':action_id' => $actionId]);

        if (empty($rows)) {
            return;
        }

        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO action_costs (action_id, cost_type_id, cost_object_id, amount, currency_id, reference_number, note)
            VALUES (:action_id, :type_id, :object_id, :amount, :currency_id, :reference_number, :note)
        SQL);
        foreach ($rows as $row) {
            $stmt->execute([
                ':action_id'        => $actionId,
                ':type_id'          => $row['type_id'],
                ':object_id'        => $row['object_id'],
                ':amount'           => $row['amount'],
                ':currency_id'      => $row['currency_id'],
                ':reference_number' => $row['reference_number'],
                ':note'             => $row['note'],
            ]);
        }
    }
}
