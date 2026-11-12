<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CalendarService;
use PHPUnit\Framework\TestCase;

/** CalendarService::buildDayGrid — the month grid of the actions calendar. */
final class CalendarServiceTest extends TestCase
{
    /** Cells of the grid by date: [date => cell]. */
    private function cells(array $grid): array
    {
        $cells = [];
        foreach ($grid as $week) {
            foreach ($week as $cell) {
                $cells[$cell['date']] = $cell;
            }
        }
        return $cells;
    }

    private function action(int $id, string $start, ?string $end): array
    {
        return ['id' => $id, 'name' => "Akce {$id}", 'public_start_at' => $start, 'public_end_at' => $end, 'status_code' => 'confirmed'];
    }

    /**
     * Vícedenní akce je celá jen v den zahájení; v dalších dnech (do konce akce) je jen „pokračující“,
     * a to jen ve dnech zobrazeného měsíce — buňky sousedního měsíce zůstávají prázdné.
     */
    public function testMultiDayActionContinuesUntilItsEndWithinTheMonth(): void
    {
        $cells = $this->cells((new CalendarService())->buildDayGrid(2026, 11, [
            $this->action(1, '2026-11-28 09:00:00', '2026-12-02 18:00:00'),
        ]));

        $this->assertSame([1], array_column($cells['2026-11-28']['actions'], 'id'));
        $this->assertSame([], $cells['2026-11-28']['continuing'], 'not "continuing" on its own start day');
        foreach (['2026-11-29', '2026-11-30'] as $d) {
            $this->assertSame([], $cells[$d]['actions'], $d);
            $this->assertSame([1], array_column($cells[$d]['continuing'], 'id'), $d);
        }
        $this->assertSame([], $cells['2026-12-01']['continuing'], 'December cells of the November grid stay empty');
    }

    /** Akce zahájená v předchozím měsíci se v tomto měsíci zobrazí jen jako pokračující (bez plného záznamu). */
    public function testActionBegunLastMonthIsOnlyContinuing(): void
    {
        $cells = $this->cells((new CalendarService())->buildDayGrid(2026, 12, [
            $this->action(1, '2026-11-28 09:00:00', '2026-12-02 18:00:00'),
            $this->action(2, '2026-11-30 09:00:00', '2026-12-01 10:00:00'),
        ]));

        $this->assertSame([1, 2], array_column($cells['2026-12-01']['continuing'], 'id'), 'one dot per continuing action');
        $this->assertSame([1], array_column($cells['2026-12-02']['continuing'], 'id'));
        $this->assertSame([], $cells['2026-12-03']['continuing']);
        foreach ($cells as $date => $cell) {
            $this->assertSame([], $cell['actions'], "{$date}: no full entry outside the start day");
        }
    }

    /** Jednodenní akce a akce bez konce nemají žádné pokračování. */
    public function testSingleDayActionHasNoContinuation(): void
    {
        $cells = $this->cells((new CalendarService())->buildDayGrid(2026, 11, [
            $this->action(1, '2026-11-10 17:00:00', '2026-11-10 19:00:00'),
            $this->action(2, '2026-11-11 17:00:00', null),
        ]));

        $this->assertSame([], array_merge(...array_column($cells, 'continuing')));
        $this->assertSame([1], array_column($cells['2026-11-10']['actions'], 'id'));
        $this->assertSame([2], array_column($cells['2026-11-11']['actions'], 'id'));
    }
}
