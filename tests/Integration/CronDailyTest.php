<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/** cron/daily.php run the way the server's crontab runs it — a separate CLI process. */
final class CronDailyTest extends DatabaseTestCase
{
    private function runCron(): array
    {
        $cmd = [PHP_BINARY, '-d', 'sendmail_path=' . ini_get('sendmail_path'), dirname(__DIR__, 2) . '/cron/daily.php'];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout  = stream_get_contents($pipes[1]);
        $stderr  = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout . $stderr];
    }

    /** cron/daily.php doběhne s kódem 0 a všech 6 úloh skončí OK. */
    public function testAllTasksSucceed(): void
    {
        [$exitCode, $output] = $this->runCron();

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame(6, substr_count($output, ' OK '), $output);
        $this->assertStringNotContainsString('CHYBA', $output);
    }

    /** Cron rozešle splatná upozornění (bez místnosti, první ve frontě) a opakované spuštění nic nezdvojí. */
    public function testItSendsDueRemindersAndRerunSendsNothingNew(): void
    {
        $organizer = Fixtures::user('organizer');
        $room      = Fixtures::place('Sál');
        Fixtures::action(['name' => 'Bez místnosti', 'public_start_at' => Fixtures::at('+3 days 17:00'), 'organizers' => [$organizer['id']]]);
        $queued = Fixtures::action(['name' => 'Ve frontě', 'public_start_at' => Fixtures::at('+12 days 17:00'), 'organizers' => [$organizer['id']]]);
        Fixtures::request($queued, $room);

        [$exitCode, $output] = $this->runCron();
        $this->assertSame(0, $exitCode, $output);
        $first = $this->notificationsOf($organizer['id']);

        $this->runCron();

        $this->assertNotEmpty(array_filter($first, fn($t) => str_contains($t, 'nemá zabranou žádnou místnost')));
        $this->assertNotEmpty(array_filter($first, fn($t) => str_contains($t, 'je první ve frontě')));
        $this->assertSame($first, $this->notificationsOf($organizer['id']), 'the second run must not duplicate anything');
    }
}
