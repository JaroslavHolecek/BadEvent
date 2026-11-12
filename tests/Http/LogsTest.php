<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\Fixtures;
use Tests\Support\HttpTestCase;

/** Audit-log page /admin/logs — visible to the TechAdmin role only. */
final class LogsTest extends HttpTestCase
{
    /** Odkaz „Logy“ v menu vidí jen TechAdmin, administrátor ho v menu vůbec nemá (ani zašedlý). */
    public function testLogsLinkOnlyInTechAdminMenu(): void
    {
        $this->loginAs('techadmin');
        $this->assertSame(1, $this->http->get('/')->xpath()->query("//nav//a[contains(@href, '/admin/logs')]")->length);

        $this->loginAs('administrator');
        $home = $this->http->get('/');
        $this->assertSame(0, $home->xpath()->query("//nav//a[contains(@href, '/admin/logs')]")->length);
        $this->assertStringNotContainsString('Logy', $home->xpath()->query('//nav')->item(0)->textContent);
    }

    /** Filtr podle data zobrazí jen záznamy z daného období; tisková stránka obsahuje tutéž tabulku. */
    public function testLogsFilteredByDate(): void
    {
        $this->loginAs('techadmin');
        $insert = $this->pdo->prepare("INSERT INTO audit_logs (user_id, entity_type, entity_id, operation, created_at) VALUES (NULL, 'action', ?, ?, ?)");
        $insert->execute([1, 'stary_zaznam', '2025-01-10 12:00:00']);
        $insert->execute([2, 'novy_zaznam', '2025-03-05 08:30:00']);

        $query = '?date_from=2025-03-01&date_to=2025-03-31';
        foreach (['/admin/logs', '/admin/logs/print'] as $path) {
            $page = $this->http->get($path . $query);
            $this->assertOk($page, $path);
            $this->assertStringContainsString('novy_zaznam', $page->text(), $path);
            $this->assertStringNotContainsString('stary_zaznam', $page->text(), $path);
        }
    }
}
