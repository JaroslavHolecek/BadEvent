<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

/** Base for tests that need the database: every test starts from a freshly seeded one. */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabase::reset();
        $this->pdo = TestDatabase::pdo();
        $_SESSION  = [];
    }

    /** Single value from a query, e.g. $this->scalar('SELECT COUNT(*) FROM actions'). */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    protected function statusOf(int $actionId): string
    {
        return (string) $this->scalar(
            'SELECT s.code FROM actions a JOIN action_statuses s ON s.id = a.actual_status WHERE a.id = ?',
            [$actionId]
        );
    }

    /** Texts of all notifications stored for the user. */
    protected function notificationsOf(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT text FROM notifications WHERE user_id = ? ORDER BY id');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
