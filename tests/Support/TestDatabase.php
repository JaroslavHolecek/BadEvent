<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Config;
use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Builds the test database from the real schema files once per run (bl_create.sql + create.sql),
 * then before every test empties all tables and re-runs database/seed.sql — so every test starts
 * from exactly the state a freshly installed application has.
 */
final class TestDatabase
{
    private static bool $built = false;
    private static array $tables = [];

    public static function pdo(): PDO
    {
        return Database::connection();
    }

    public static function ensureBuilt(): void
    {
        if (self::$built) {
            return;
        }

        $cfg = Config::load('database');
        if (!str_ends_with((string) $cfg['database'], '_test')) {
            throw new RuntimeException(
                "Refusing to rebuild database '{$cfg['database']}': the test database name must end with '_test' (tests/.envConfig/database.php)."
            );
        }

        $root = dirname(__DIR__, 2);
        $pdo  = self::pdo();
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
        // PDO::exec without parameters sends the whole file in one go, so $$-quoted function bodies are fine.
        $pdo->exec((string) file_get_contents($root . '/vendor/BadLogin/src/db/bl_create.sql'));
        $pdo->exec((string) file_get_contents($root . '/database/create.sql'));

        self::$tables = $pdo->query("SELECT quote_ident(tablename) FROM pg_tables WHERE schemaname = 'public'")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::$built = true;
    }

    public static function reset(): void
    {
        self::ensureBuilt();

        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('TRUNCATE ' . implode(', ', self::$tables) . ' RESTART IDENTITY CASCADE');
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/seed.sql'));
    }
}
