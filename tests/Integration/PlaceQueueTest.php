<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\ActionRepository;
use App\Services\ActionWorkflowService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/**
 * Room blocking: requests (action_places_desire) form a queue per room ordered by request time;
 * only the first non-colliding request may be confirmed (action_places). Two actions collide unless
 * one's public end is at or before the other's staff start.
 */
final class PlaceQueueTest extends DatabaseTestCase
{
    private ActionRepository $repo;
    private int $room;
    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ActionRepository();
        $this->room = Fixtures::place('Velký sál', 'VS');
        $this->day  = date('Y-m-d', strtotime('+20 days'));
    }

    /** Action on the test day: public $from–$to, staff 30 minutes around it unless given. */
    private function actionAt(string $from, string $to, array $extra = []): int
    {
        return Fixtures::action($extra + [
            'public_start_at' => "{$this->day} {$from}:00",
            'public_end_at'   => "{$this->day} {$to}:00",
        ]);
    }

    /** Request made $minutesAgo minutes ago — explicit, so the queue order never depends on insert timing. */
    private function requestedAgo(int $actionId, int $minutesAgo, ?int $room = null, ?string $note = null): void
    {
        Fixtures::request($actionId, $room ?? $this->room, Fixtures::at("-{$minutesAgo} minutes"), $note);
    }

    private function isConfirmed(int $actionId, ?int $room = null): bool
    {
        return (bool) $this->scalar('SELECT COUNT(*) FROM action_places WHERE action_id = ? AND place_id = ?', [$actionId, $room ?? $this->room]);
    }

    private function isRequested(int $actionId, ?int $room = null): bool
    {
        return (bool) $this->scalar('SELECT COUNT(*) FROM action_places_desire WHERE action_id = ? AND place_id = ?', [$actionId, $room ?? $this->room]);
    }

    /** Dřívější požadavek je ve frontě první a jen ten lze zabrat; druhý v pořadí zabrat nejde. */
    public function testEarlierRequestIsFirstAndOnlyItCanBeConfirmed(): void
    {
        $first  = $this->actionAt('17:00', '19:00');
        $second = $this->actionAt('18:00', '20:00');
        $this->requestedAgo($first, 60);
        $this->requestedAgo($second, 30);

        $this->assertSame(1, $this->repo->queuePosition($first, $this->room));
        $this->assertSame(2, $this->repo->queuePosition($second, $this->room));

        $this->assertFalse($this->repo->confirmPlaceDesire($second, $this->room));
        $this->assertFalse($this->isConfirmed($second));

        $this->assertTrue($this->repo->confirmPlaceDesire($first, $this->room));
        $this->assertTrue($this->isConfirmed($first));
    }

    /** Pořadí ve frontě určuje čas podání požadavku, ne čas konání akce. */
    public function testQueueOrderIsByRequestTimeNotByActionTime(): void
    {
        $later  = $this->actionAt('18:00', '20:00');
        $sooner = $this->actionAt('17:00', '19:00');
        $this->requestedAgo($later, 60);   // asked first
        $this->requestedAgo($sooner, 30);

        $this->assertSame(1, $this->repo->queuePosition($later, $this->room));
        $this->assertSame(2, $this->repo->queuePosition($sooner, $this->room));
    }

    /** Požadavky na různé místnosti si nekonkurují. */
    public function testDifferentRoomsDoNotCompete(): void
    {
        $other = Fixtures::place('Malý sál');
        $a     = $this->actionAt('17:00', '19:00');
        $b     = $this->actionAt('17:00', '19:00');
        $this->requestedAgo($a, 60);
        $this->requestedAgo($b, 30, $other);

        $this->assertSame(1, $this->repo->queuePosition($b, $other));
    }

    /** Akce, které se časově nepřekrývají, si místnost neblokují. */
    public function testNonOverlappingActionsDoNotCompete(): void
    {
        $morning = $this->actionAt('09:00', '11:00');
        $evening = $this->actionAt('17:00', '19:00');
        $this->requestedAgo($morning, 60);
        $this->requestedAgo($evening, 30);

        $this->assertSame(1, $this->repo->queuePosition($evening, $this->room));
        $this->assertTrue($this->repo->canConfirmPlaceDesire($evening, $this->room));
    }

    /** Do blokace se počítá i čas pro personál (příprava), nejen čas pro veřejnost. */
    public function testStaffTimeIsPartOfTheBlocking(): void
    {
        // Public times don't overlap (19:00 end vs 19:15 start), but the second one's staff arrive at 18:45.
        $first  = $this->actionAt('17:00', '19:00');
        $second = $this->actionAt('19:15', '21:00', ['staff_start_at' => "{$this->day} 18:45:00"]);
        $this->requestedAgo($first, 60);
        $this->requestedAgo($second, 30);

        $this->assertSame(2, $this->repo->queuePosition($second, $this->room));
    }

    /** Akce navazující přesně na sebe (konec = příchod personálu další) se nepřekrývají. */
    public function testBackToBackActionsDoNotCompete(): void
    {
        // Staff of the second one arrive exactly when the first one ends.
        $first  = $this->actionAt('17:00', '19:00');
        $second = $this->actionAt('19:30', '21:00', ['staff_start_at' => "{$this->day} 19:00:00"]);
        $this->requestedAgo($first, 60);
        $this->requestedAgo($second, 30);

        $this->assertSame(1, $this->repo->queuePosition($second, $this->room));
    }

    /** Požadavky zrušených a nedojednaných akcí frontu neblokují. */
    public function testCancelledAndNotAgreedRequestsDoNotBlock(): void
    {
        $cancelled = $this->actionAt('17:00', '19:00', ['status' => 'cancelled']);
        $notAgreed = $this->actionAt('17:00', '19:00', ['status' => 'not_agreed']);
        $live      = $this->actionAt('17:00', '19:00');
        $this->requestedAgo($cancelled, 90);
        $this->requestedAgo($notAgreed, 60);
        $this->requestedAgo($live, 30);

        $this->assertSame(1, $this->repo->queuePosition($live, $this->room));
    }

    /** Akce bez zadaných časů se bere jako kolidující (konzervativně). */
    public function testUnknownTimesAreTreatedAsColliding(): void
    {
        $undated = Fixtures::action(['public_start_at' => null, 'public_end_at' => null, 'staff_start_at' => null, 'staff_end_at' => null]);
        $dated   = $this->actionAt('17:00', '19:00');
        $this->requestedAgo($undated, 60);
        $this->requestedAgo($dated, 30);

        $this->assertSame(2, $this->repo->queuePosition($dated, $this->room));
    }

    /** Po zrušení první akce se fronta posune a další akce může místnost zabrat. */
    public function testQueueMovesUpWhenTheFirstActionIsCancelled(): void
    {
        $admin = Fixtures::user('administrator');
        $a = $this->actionAt('17:00', '19:00', ['status' => 'confirmed']);
        $b = $this->actionAt('17:00', '19:00');
        $c = $this->actionAt('17:00', '19:00');
        $this->requestedAgo($a, 90);
        $this->requestedAgo($b, 60);
        $this->requestedAgo($c, 30);
        Fixtures::confirm($a, $this->room);
        $this->assertSame(3, $this->repo->queuePosition($c, $this->room));

        (new ActionWorkflowService())->transition($a, 'cancelled', $admin['id']);

        $this->assertSame(1, $this->repo->queuePosition($b, $this->room));
        $this->assertSame(2, $this->repo->queuePosition($c, $this->room));
        $this->assertTrue($this->repo->confirmPlaceDesire($b, $this->room));
    }

    /** Nový požadavek lze rovnou zabrat jen pokud o daný čas nikdo jiný nežádá. */
    public function testBrandNewRequestIsConfirmableOnlyWhenNobodyElseWantsTheSlot(): void
    {
        $existing  = $this->actionAt('17:00', '19:00');
        $colliding = $this->actionAt('18:00', '20:00');
        $elsewhen  = $this->actionAt('09:00', '11:00');
        $this->requestedAgo($existing, 30);

        $this->assertFalse($this->repo->canConfirmNewRequest($colliding, $this->room));
        $this->assertTrue($this->repo->canConfirmNewRequest($elsewhen, $this->room));
    }

    /** Formulář nezabere místnost, když akce není první ve frontě; uloží se jen požadavek. */
    public function testFormCannotConfirmARoomWhenNotFirstInQueue(): void
    {
        $first  = $this->actionAt('17:00', '19:00');
        $second = $this->actionAt('17:00', '19:00');
        $this->requestedAgo($first, 60);

        // What the create/edit form submits: the room picked as "confirmed".
        $this->repo->syncPlaceStates($second, [$this->room => 'confirmed']);

        $this->assertTrue($this->isRequested($second), 'the request is stored');
        $this->assertFalse($this->isConfirmed($second), 'but not confirmed — someone is ahead in the queue');
    }

    /** Volnou místnost formulář rovnou zabere a přenese poznámku k místnosti. */
    public function testFormConfirmsAFreeRoomAndCarriesTheNote(): void
    {
        $action = $this->actionAt('17:00', '19:00');

        $this->repo->syncPlaceStates($action, [$this->room => 'confirmed'], [$this->room => 'Připravit projektor']);

        $this->assertTrue($this->isConfirmed($action));
        $this->assertSame('Připravit projektor', $this->scalar('SELECT note FROM action_places WHERE action_id = ?', [$action]));
    }

    /** Odškrtnutí místnosti zruší požadavek i zabrání (místnost nezůstane skrytě blokovaná). */
    public function testUncheckingARoomRemovesRequestAndConfirmation(): void
    {
        $action = $this->actionAt('17:00', '19:00');
        $this->repo->syncPlaceStates($action, [$this->room => 'confirmed']);

        $this->repo->syncPlaceStates($action, [$this->room => 'none']);

        $this->assertFalse($this->isRequested($action));
        $this->assertFalse($this->isConfirmed($action), 'a removed room must not stay silently booked');
    }

    /** Změna ze „zabráno“ na „požadováno“ zachová čas požadavku (pořadí ve frontě) a aktualizuje poznámku. */
    public function testDowngradingToRequestedKeepsTheQueuePlace(): void
    {
        $action = $this->actionAt('17:00', '19:00');
        $this->requestedAgo($action, 120);
        Fixtures::confirm($action, $this->room);
        $createdAt = $this->scalar('SELECT created_at FROM action_places_desire WHERE action_id = ?', [$action]);

        $this->repo->syncPlaceStates($action, [$this->room => 'requested'], [$this->room => 'nová poznámka']);

        $this->assertFalse($this->isConfirmed($action));
        $this->assertSame($createdAt, $this->scalar('SELECT created_at FROM action_places_desire WHERE action_id = ?', [$action]), 'request time = queue priority, must survive a save');
        $this->assertSame('nová poznámka', $this->scalar('SELECT note FROM action_places_desire WHERE action_id = ?', [$action]));
    }

    /** Upravená poznámka se propíše i do již zabrané místnosti (regrese chyby z 21. 9. 2026). */
    public function testEditedNoteReachesAnAlreadyConfirmedRoom(): void
    {
        // Regression (2026-09-21): the maintenance export reads action_places.note, which went stale.
        $action = $this->actionAt('17:00', '19:00');
        $this->repo->syncPlaceStates($action, [$this->room => 'confirmed'], [$this->room => 'stará']);

        $this->repo->syncPlaceStates($action, [$this->room => 'confirmed'], [$this->room => 'nová']);

        $this->assertSame('nová', $this->scalar('SELECT note FROM action_places WHERE action_id = ?', [$action]));
    }

    /** Přesun zabrané akce do času, kde je dřívější požadavek jiné akce, zabrání zruší, požadavek zůstane. */
    public function testMovingAConfirmedActionOntoAnEarlierRequestDropsTheConfirmation(): void
    {
        $earlier = $this->actionAt('17:00', '19:00');
        $this->requestedAgo($earlier, 120);
        $mover = $this->actionAt('09:00', '11:00');
        $this->requestedAgo($mover, 60);
        Fixtures::confirm($mover, $this->room);

        // The organizer reschedules it into the evening slot and saves the form unchanged.
        $this->pdo->prepare('UPDATE actions SET public_start_at = ?, public_end_at = ?, staff_start_at = ?, staff_end_at = ? WHERE id = ?')
            ->execute(["{$this->day} 17:30:00", "{$this->day} 18:30:00", "{$this->day} 17:00:00", "{$this->day} 19:00:00", $mover]);
        $this->repo->syncPlaceStates($mover, [$this->room => 'confirmed']);

        $this->assertFalse($this->isConfirmed($mover), 'confirmation no longer valid in the new time slot');
        $this->assertTrue($this->isRequested($mover), 'but the request stays in the queue');
    }
}
