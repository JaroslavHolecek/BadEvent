<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\ScheduledRunRepository;
use Tests\Support\DatabaseTestCase;

/** scheduled_runs — a job triggered by page loads (dashboard notification checks) runs at most once per interval. */
final class ScheduledRunRepositoryTest extends DatabaseTestCase
{
    private function lastRunMinutesAgo(string $code, int $minutes): void
    {
        $this->pdo->prepare("UPDATE scheduled_runs SET last_run_at = NOW() - make_interval(mins => ?) WHERE code = ?")
            ->execute([$minutes, $code]);
    }

    /** Úloha, která ještě nikdy neběžela, se spustí; hned další pokus už ne. */
    public function testFirstClaimWinsAndARepeatWithinTheIntervalDoesNot(): void
    {
        $runs = new ScheduledRunRepository();

        $this->assertTrue($runs->claimIfDue('job', 1200));
        $this->assertFalse($runs->claimIfDue('job', 1200));
        $this->assertFalse((new ScheduledRunRepository())->claimIfDue('job', 1200));
    }

    /** Po uplynutí intervalu se úloha spustí znovu a čas posledního běhu se posune; před jeho uplynutím ne. */
    public function testItIsDueAgainOnlyAfterTheInterval(): void
    {
        $runs = new ScheduledRunRepository();
        $runs->claimIfDue('job', 1200);

        $this->lastRunMinutesAgo('job', 1199);
        $this->assertFalse($runs->claimIfDue('job', 1200), '1 minute before the interval passes');

        $this->lastRunMinutesAgo('job', 1201);
        $this->assertTrue($runs->claimIfDue('job', 1200), 'interval passed');
        $this->assertFalse($runs->claimIfDue('job', 1200), 'the new run time was stamped');
    }

    /** Interval 0 znamená spustit pokaždé. */
    public function testZeroIntervalIsAlwaysDue(): void
    {
        $runs = new ScheduledRunRepository();

        $this->assertTrue($runs->claimIfDue('job', 0));
        $this->assertTrue($runs->claimIfDue('job', 0));
    }

    /** Záporný interval znamená nikdy nespouštět — ani úlohu, která ještě neběžela; nic se nezapíše. */
    public function testNegativeIntervalIsNeverDue(): void
    {
        $runs = new ScheduledRunRepository();

        $this->assertFalse($runs->claimIfDue('job', -1));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM scheduled_runs WHERE code = 'job'"));
    }

    /** Různé úlohy si záznamy nepřepisují. */
    public function testDifferentJobsAreIndependent(): void
    {
        $runs = new ScheduledRunRepository();

        $this->assertTrue($runs->claimIfDue('a', 1200));
        $this->assertTrue($runs->claimIfDue('b', 1200));
    }
}
