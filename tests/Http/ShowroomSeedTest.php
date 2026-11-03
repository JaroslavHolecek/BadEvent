<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\Support\HttpTestCase;

/** database/seed_showroom.sql — the demo data loads cleanly and the app can be shown off with it. */
final class ShowroomSeedTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/seed_showroom.sql'));
    }

    /** Účet admin@ad.min s heslem admin se přihlásí jako Administrátor a může se přepnout na Organizátora. */
    public function testAdminAccountLogsIn(): void
    {
        $this->http->login('admin@ad.min', 'admin');

        $page = $this->http->get('/');
        $this->assertOk($page);
        $this->assertStringContainsString('Admin Ukázkový', $page->text());
        $this->assertSame(['administrator', 'organizer'], $this->pdo->query(
            "SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id JOIN bl_login l ON l.id = ur.user_id
             WHERE l.email = 'admin@ad.min' ORDER BY r.id"
        )->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame('administrator', $this->scalar(
            "SELECT r.code FROM app_users u JOIN roles r ON r.id = u.active_role_id JOIN bl_login l ON l.id = u.id WHERE l.email = 'admin@ad.min'"
        ));
    }

    /** Ostatní ukázkové účty mají heslo „demo“ (např. organizátorka Kateřina Šimková). */
    public function testOtherSampleAccountsUseDemoPassword(): void
    {
        (new HttpClient())->login('katerina.simkova@knihovna.example', 'demo');
        $this->addToAssertionCount(1);   // login() throws when it fails
    }

    /** Ukázková data pokrývají všechny stavy akcí, docházku, příjmy (i v EUR a od dárce), výdaje, frontu na místnost a vícedenní akci. */
    public function testDataCoverWhatADemoNeeds(): void
    {
        $this->assertSame(
            ['cancelled', 'closed', 'completed', 'confirmed', 'draft', 'not_agreed'],
            $this->pdo->query('SELECT DISTINCT s.code FROM actions a JOIN action_statuses s ON s.id = a.actual_status ORDER BY 1')->fetchAll(\PDO::FETCH_COLUMN)
        );
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(DISTINCT action_id) FROM action_attendance'));
        $this->assertGreaterThan(0, (int) $this->scalar("SELECT COUNT(*) FROM action_incomes i JOIN currencies c ON c.id = i.currency_id WHERE c.code = 'EUR'"));
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM action_incomes WHERE donor_id IS NOT NULL'));
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM action_costs'));
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM actions WHERE public_end_at::date > public_start_at::date'), 'multi-day exhibition');
        $this->assertGreaterThan(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM action_places_desire d
             WHERE NOT EXISTS (SELECT 1 FROM action_places ap WHERE ap.action_id = d.action_id AND ap.place_id = d.place_id)'
        ), 'requested rooms waiting in a queue');
        $this->assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM action_places_desire d JOIN actions a ON a.id = d.action_id JOIN action_statuses s ON s.id = a.actual_status
             WHERE s.code IN ('cancelled', 'not_agreed')"
        ), 'cancelled/not-agreed actions hold no rooms, like in the app');
        $this->assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM action_places ap
             WHERE NOT EXISTS (SELECT 1 FROM action_places_desire d WHERE d.action_id = ap.action_id AND d.place_id = ap.place_id)'
        ), 'every assigned room is also a request, like in the app');
    }

    /** S ukázkovými daty se otevřou všechny hlavní stránky, detail každé akce i všechny exporty pro tisk. */
    public function testMainPagesOpenWithTheSampleData(): void
    {
        $this->http->login('admin@ad.min', 'admin');
        $range = ['date_from' => date('Y-m-d', strtotime('-30 days')), 'date_to' => date('Y-m-d', strtotime('+30 days'))];

        foreach (['/', '/actions', '/events', '/calendar/places', '/people', '/admin/roles', '/admin/dictionaries', '/admin/building-code-duty', '/export'] as $path) {
            $this->assertOk($this->http->get($path), $path);
        }
        foreach (['/export/print', '/export/statistika/print', '/export/rooms/print', '/export/media-team/print', '/export/maintenance/print', '/export/weekly/print'] as $path) {
            $this->assertOk($this->http->get($path, $range), $path);
        }
        foreach ($this->pdo->query('SELECT id FROM actions ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->assertOk($this->http->get("/actions/{$id}"), "action {$id}");
        }

        $plan = $this->http->get('/export/weekly/print', $range)->text();
        $this->assertStringContainsString('Výstava: Kladno na starých fotografiích', $plan);
        $this->assertStringNotContainsString('Šachový turnaj pro rodiče s dětmi', $plan, 'cancelled');
    }

    /** Opakované nahrání se odmítne a nic nezdvojí. */
    public function testLoadingTwiceIsRefused(): void
    {
        $actions = (int) $this->scalar('SELECT COUNT(*) FROM actions');

        try {
            $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/seed_showroom.sql'));
            $this->fail('expected the guard to stop the second load');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('already loaded', $e->getMessage());
        } finally {
            $this->pdo->exec('ROLLBACK');   // the file's own BEGIN left the session in the aborted transaction
        }

        $this->assertSame($actions, (int) $this->scalar('SELECT COUNT(*) FROM actions'));
    }
}
