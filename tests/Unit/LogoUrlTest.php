<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Themes (public/themes/<name>/): theme_name(), theme_file(), theme_css_url(), logo_url(). */
final class LogoUrlTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        // Fake public/ with the demo theme and a company theme that has only its own CSS (no logo).
        $this->publicDir = sys_get_temp_dir() . '/badevent-themes-' . bin2hex(random_bytes(4));
        mkdir($this->publicDir . '/themes/demo', 0777, true);
        mkdir($this->publicDir . '/themes/firma', 0777, true);
        touch($this->publicDir . '/themes/demo/theme.css');
        touch($this->publicDir . '/themes/demo/logo.svg');
        touch($this->publicDir . '/themes/firma/theme.css');
    }

    protected function tearDown(): void
    {
        foreach (['demo/theme.css', 'demo/logo.svg', 'firma/theme.css'] as $f) {
            @unlink($this->publicDir . '/themes/' . $f);
        }
        @rmdir($this->publicDir . '/themes/demo');
        @rmdir($this->publicDir . '/themes/firma');
        @rmdir($this->publicDir . '/themes');
        @rmdir($this->publicDir);
    }

    /** Testy běží s ukázkovým motivem: logo i CSS jsou z public/themes/demo/ a mají parametr proti zastaralé cache. */
    public function testTestsUseTheDemoTheme(): void
    {
        $this->assertSame('demo', theme_name());
        $this->assertMatchesRegularExpression('#^/themes/demo/logo\.svg\?v=\d+$#', logo_url());
        $this->assertMatchesRegularExpression('#^/themes/demo/theme\.css\?v=\d+$#', (string) theme_css_url());
    }

    /** Nastavený existující motiv se použije; soubor, který v něm chybí (logo), se vezme z demo motivu. */
    public function testCompanyThemeFallsBackToDemoForMissingFiles(): void
    {
        $config = ['theme' => 'firma'];
        $this->assertSame('firma', theme_name($config, $this->publicDir));
        $this->assertSame('/themes/firma/theme.css', theme_file('theme.css', $config, $this->publicDir));
        $this->assertSame('/themes/demo/logo.svg', theme_file('logo.svg', $config, $this->publicDir));
        $this->assertNull(theme_file('favicon.ico', $config, $this->publicDir));
    }

    /** Neexistující, chybějící nebo nebezpečný název motivu (např. s „../“) vede na demo motiv. */
    public function testInvalidThemeFallsBackToDemo(): void
    {
        foreach ([[], ['theme' => 'neexistuje'], ['theme' => '../demo'], ['theme' => ''], ['theme' => 'firma/']] as $config) {
            $this->assertSame('demo', theme_name($config, $this->publicDir), var_export($config, true));
        }
    }
}
