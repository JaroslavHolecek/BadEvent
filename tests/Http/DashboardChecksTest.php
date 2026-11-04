<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Repositories\ScheduledRunRepository;
use Tests\Support\Fixtures;
use Tests\Support\HttpTestCase;

/**
 * Time-based notification checks triggered by "Můj přehled": by any user, over all actions, at most
 * once per time_related_checks_dashboard_interval_minutes (1200 in the test config).
 */
final class DashboardChecksTest extends HttpTestCase
{
    /** Confirmed action starting in 3 days with no room — exactly one reminder ("no room") when the checks run. */
    private function actionWithoutRoom(int $organizerId): void
    {
        Fixtures::action([
            'name'            => 'Bez místnosti',
            'status'          => 'confirmed',
            'public_start_at' => Fixtures::at('+3 days 10:00'),
            'organizers'      => [$organizerId],
        ]);
    }

    private function lastDashboardRunMinutesAgo(int $minutes): void
    {
        (new ScheduledRunRepository())->claimIfDue(ScheduledRunRepository::DASHBOARD_CHECKS, 0);
        $this->pdo->prepare('UPDATE scheduled_runs SET last_run_at = NOW() - make_interval(mins => ?) WHERE code = ?')
            ->execute([$minutes, ScheduledRunRepository::DASHBOARD_CHECKS]);
    }

    /** První zobrazení titulní strany spustí kontroly nad všemi akcemi — i nad akcemi jiných uživatelů — a zapamatuje si čas. */
    public function testFirstLoadRunsChecksOverAllActions(): void
    {
        $organizer = Fixtures::user('organizer');
        $this->actionWithoutRoom($organizer['id']);
        $this->loginAs('stuff'); // a user with nothing to do with the action

        $this->assertOk($this->http->get('/'));

        $this->assertCount(1, $this->notificationsOf($organizer['id']));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM scheduled_runs WHERE code = ? AND last_run_at > NOW() - INTERVAL \'1 minute\'', [ScheduledRunRepository::DASHBOARD_CHECKS]));
    }

    /** Když kontroly z titulní strany proběhly před méně než 20 h (od kohokoli), další zobrazení je nespustí. */
    public function testLoadWithinTheIntervalRunsNothing(): void
    {
        $this->lastDashboardRunMinutesAgo(19 * 60);
        $organizer = $this->loginAs('organizer');
        $this->actionWithoutRoom($organizer['id']);

        $this->assertOk($this->http->get('/'));

        $this->assertSame([], $this->notificationsOf($organizer['id']), 'checks ran 19 h ago');
    }

    /** Po uplynutí 20 h od posledního běhu zobrazení titulní strany kontroly spustí znovu. */
    public function testLoadAfterTheIntervalRunsChecksAgain(): void
    {
        $this->lastDashboardRunMinutesAgo(21 * 60);
        $organizer = $this->loginAs('organizer');
        $this->actionWithoutRoom($organizer['id']);

        $this->assertOk($this->http->get('/'));

        $this->assertCount(1, $this->notificationsOf($organizer['id']));
    }
}
