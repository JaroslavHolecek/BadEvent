<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\CalendarRepository;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/** Which actions the room calendar and the actions calendar load for a month. */
final class CalendarRepositoryTest extends DatabaseTestCase
{
    /** Action ids per room name in placeOccupancyForMonth(). */
    private function occupancy(int $year, int $month): array
    {
        $byRoom = [];
        foreach ((new CalendarRepository())->placeOccupancyForMonth($year, $month) as $place) {
            $byRoom[$place['name']] = array_map('intval', array_column($place['actions'], 'id'));
            sort($byRoom[$place['name']]);
        }
        return $byRoom;
    }

    private function longAction(int $room, bool $confirmed, string $status = 'confirmed'): int
    {
        $action = Fixtures::action([
            'public_start_at' => '2030-11-20 09:00:00',
            'public_end_at'   => '2030-12-02 18:00:00',
            'status'          => $status,
        ]);
        $confirmed ? Fixtures::confirm($action, $room) : Fixtures::request($action, $room);
        return $action;
    }

    /**
     * Kalendář místností: vícedenní akce zahájená v listopadu obsazuje místnost i v prosinci (potvrzená
     * i čekající žádost); v lednu už ne. Dříve se brala jen akce, která v měsíci začíná.
     */
    public function testRoomCalendarShowsActionRunningIntoNextMonth(): void
    {
        $gallery   = Fixtures::place('Galerie');
        $hall      = Fixtures::place('Sál');
        $confirmed = $this->longAction($gallery, true);
        $queued    = $this->longAction($hall, false, 'draft');

        foreach ([11, 12] as $month) {
            $rooms = $this->occupancy(2030, $month);
            $this->assertSame([$confirmed], $rooms['Galerie'], "confirmed, month {$month}");
            $this->assertSame([$queued], $rooms['Sál'], "queued, month {$month}");
        }
        $this->assertSame(['Galerie' => [], 'Sál' => []], $this->occupancy(2031, 1));
    }

    /** Akce, jejíž příprava (čas pro personál) začíná poslední den předchozího měsíce, je vidět i v tom měsíci. */
    public function testRoomCalendarUsesStaffTimesToo(): void
    {
        $room   = Fixtures::place('Sál');
        $action = Fixtures::action([
            'public_start_at' => '2030-12-01 09:00:00',
            'staff_start_at'  => '2030-11-30 20:00:00',
        ]);
        Fixtures::confirm($action, $room);

        $this->assertSame([$action], $this->occupancy(2030, 11)['Sál']);
        $this->assertSame([$action], $this->occupancy(2030, 12)['Sál']);
    }

    /** Zrušená ani nedojednaná akce s potvrzenou místností se v kalendáři místností nezobrazí; místnost zůstane v seznamu. */
    public function testRoomCalendarSkipsCancelledAndNotAgreedActions(): void
    {
        $room = Fixtures::place('Sál');
        $this->longAction($room, true, 'cancelled');
        $this->longAction($room, true, 'not_agreed');

        $this->assertSame(['Sál' => []], $this->occupancy(2030, 12));
    }

    /** Kalendář akcí načte i akci zahájenou v předchozím měsíci, která v daném měsíci ještě probíhá. */
    public function testActionsCalendarLoadsActionRunningIntoTheMonth(): void
    {
        $running = Fixtures::action(['public_start_at' => '2030-11-20 09:00:00', 'public_end_at' => '2030-12-02 18:00:00']);
        $ended   = Fixtures::action(['public_start_at' => '2030-11-25 09:00:00', 'public_end_at' => '2030-11-25 18:00:00']);

        $ids = array_map('intval', array_column((new CalendarRepository())->actionsForMonth(2030, 12, ['draft']), 'id'));

        $this->assertContains($running, $ids);
        $this->assertNotContains($ended, $ids);
    }
}
