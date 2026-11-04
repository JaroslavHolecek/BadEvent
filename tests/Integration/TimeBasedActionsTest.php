<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\BuildingCodeDutyRepository;
use App\Services\NotificationService;
use Tests\Support\DatabaseTestCase;

/** NotificationService::runTimeBasedActions() — the single list of time-based actions run by cron and the dashboard. */
final class TimeBasedActionsTest extends DatabaseTestCase
{
    private function dutyRow(string $date): void
    {
        $this->pdo->prepare('INSERT INTO building_code_duty (duty_date, note) VALUES (?, ?)')->execute([$date, 'poznámka']);
    }

    private function dutyDates(): array
    {
        return $this->pdo->query('SELECT duty_date::text FROM building_code_duty ORDER BY duty_date')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Seznam obsahuje všechny kontroly upozornění i úklid kódování budovy a všechny proběhnou bez chyby. */
    public function testAllActionsRunWithoutError(): void
    {
        $results = (new NotificationService())->runTimeBasedActions();

        $this->assertSame(
            ['Fronta na místnosti', 'Blížící se Rozjednané akce', 'Akce po termínu', 'Akce bez místnosti', 'Opakované odeslání emailů', 'Úklid kódování budovy'],
            array_keys($results)
        );
        foreach ($results as $name => $result) {
            $this->assertNull($result['error'], $name . ': ' . ($result['error'] ? $result['error']->getMessage() : ''));
        }
    }

    /** Úklid kódování budovy smaže dny starší 2 měsíců (i s poznámkou), novější a budoucí nechá. */
    public function testBuildingCodeDutyCleanupDropsOnlyOldDays(): void
    {
        $old    = date('Y-m-d', strtotime('-3 months'));
        $recent = date('Y-m-d', strtotime('-1 month'));
        $future = date('Y-m-d', strtotime('+1 month'));
        foreach ([$old, $recent, $future] as $date) {
            $this->dutyRow($date);
        }

        (new NotificationService())->runTimeBasedActions();

        $this->assertSame([$recent, $future], $this->dutyDates());
    }

    /** Samotné použití repozitáře (kalendář, titulní strana) už nic nemaže — úklid běží jen v rámci časových akcí. */
    public function testUsingTheRepositoryNoLongerCleansUp(): void
    {
        $old = date('Y-m-d', strtotime('-3 months'));
        $this->dutyRow($old);

        (new BuildingCodeDutyRepository())->forDate(date('Y-m-d'));

        $this->assertSame([$old], $this->dutyDates());
    }
}
