<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Loads the runtime config files (app.php, bl_config.php, database.php).
 * They live in .envConfig/ by default; the BADEVENT_CONFIG_DIR environment variable points
 * somewhere else instead — used by the test suite (see tests/README.md), never set in production.
 */
final class Config
{
    public static function dir(): string
    {
        $dir = getenv('BADEVENT_CONFIG_DIR');
        return ($dir !== false && $dir !== '') ? rtrim($dir, '/') : __DIR__ . '/../../.envConfig';
    }

    public static function load(string $name): array
    {
        return require self::dir() . '/' . $name . '.php';
    }
}
