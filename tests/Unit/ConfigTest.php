<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string|false $original;

    protected function setUp(): void
    {
        $this->original = getenv('BADEVENT_CONFIG_DIR');
    }

    protected function tearDown(): void
    {
        putenv($this->original === false ? 'BADEVENT_CONFIG_DIR' : 'BADEVENT_CONFIG_DIR=' . $this->original);
    }

    /** Testy čtou konfiguraci z tests/.envConfig a databáze je testovací (*_test). */
    public function testTestsUseTheirOwnConfigDirectory(): void
    {
        $this->assertSame(realpath(__DIR__ . '/../.envConfig'), realpath(Config::dir()));
        $this->assertStringEndsWith('_test', Config::load('database')['database']);
    }

    /** Bez proměnné BADEVENT_CONFIG_DIR (produkce) se konfigurace čte z .envConfig/. */
    public function testWithoutOverrideFallsBackToEnvConfig(): void
    {
        putenv('BADEVENT_CONFIG_DIR');

        $this->assertSame(
            realpath(__DIR__ . '/../..') . '/.envConfig',
            realpath(dirname(Config::dir())) . '/' . basename(Config::dir())
        );
    }

    /** bl_config.php přebírá base_url a mail_from z app.php. */
    public function testBlConfigTakesSharedValuesFromAppConfig(): void
    {
        $app = Config::load('app');
        $bl  = Config::load('bl_config');

        $this->assertSame($app['base_url'], $bl['base_url']);
        $this->assertSame($app['mail_from'], $bl['mail_from']);
    }
}
