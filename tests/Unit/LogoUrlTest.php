<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** logo_url() — the organization logo configured as 'logo_path' in .envConfig/app.php. */
final class LogoUrlTest extends TestCase
{
    /** Logo z 'logo_path' (cesta v public/) dostane adresu aplikace a parametr proti zastaralé cache. */
    public function testConfiguredLogoPathBecomesAssetUrl(): void
    {
        $this->assertMatchesRegularExpression('#^/assets/images/logo-min\.svg\?v=\d+$#', logo_url());
    }
}
