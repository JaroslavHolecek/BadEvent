<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\PlaceQueueService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/** Notifications about the room queue: "you're first", then 7+ days (organizers) and 14+ days (admins) without confirming. */
final class PlaceQueueServiceTest extends DatabaseTestCase
{
    private int $room;
    private array $admin;
    private array $organizerA;
    private array $organizerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->room       = Fixtures::place('Klubovna');
        $this->admin      = Fixtures::user('administrator');
        $this->organizerA = Fixtures::user('organizer');
        $this->organizerB = Fixtures::user('organizer');
    }

    private function competingActions(): array
    {
        $start = Fixtures::at('+15 days 17:00');
        $a = Fixtures::action(['name' => 'Akce A', 'public_start_at' => $start, 'organizers' => [$this->organizerA['id']]]);
        $b = Fixtures::action(['name' => 'Akce B', 'public_start_at' => $start, 'organizers' => [$this->organizerB['id']]]);
        Fixtures::request($a, $this->room, Fixtures::at('-2 hours'));
        Fixtures::request($b, $this->room, Fixtures::at('-1 hour'));
        return [$a, $b];
    }

    /** Organizátor akce, která je první ve frontě, dostane upozornění právě jednou; druhý v pořadí nic. */
    public function testFirstInQueueIsNotifiedOnceAndOthersNot(): void
    {
        [$a, $b] = $this->competingActions();

        (new PlaceQueueService())->reconcile();
        (new PlaceQueueService())->reconcile();

        $this->assertSame(['Akce "Akce A" je první ve frontě na místnost "Klubovna". Můžete si ji zabrat.'], $this->notificationsOf($this->organizerA['id']));
        $this->assertSame([], $this->notificationsOf($this->organizerB['id']));
        $this->assertNotNull($this->scalar('SELECT first_at_queue_at FROM action_places_desire WHERE action_id = ?', [$a]));
        $this->assertNull($this->scalar('SELECT first_at_queue_at FROM action_places_desire WHERE action_id = ?', [$b]));
    }

    /** Když první akce odpadne, upozornění dostane další v pořadí. */
    public function testNextInLineIsNotifiedWhenTheFirstGoesAway(): void
    {
        [$a, $b] = $this->competingActions();
        (new PlaceQueueService())->reconcile();

        Fixtures::setStatus($a, 'cancelled');
        (new PlaceQueueService())->reconcile();

        $this->assertCount(1, $this->notificationsOf($this->organizerB['id']));
        $this->assertNull($this->scalar('SELECT first_at_queue_at FROM action_places_desire WHERE action_id = ?', [$a]));
    }

    /** Akce, která už místnost zabrala, upozornění „jste první“ nedostane. */
    public function testAlreadyConfirmedRoomIsNotNotified(): void
    {
        [$a] = $this->competingActions();
        Fixtures::confirm($a, $this->room);

        (new PlaceQueueService())->reconcile();

        $this->assertSame([], $this->notificationsOf($this->organizerA['id']));
    }

    /** Po 7 dnech bez zabrání jde připomínka organizátorům (jednou), po 14 dnech i administrátorům. */
    public function testLongWaitingFirstPlaceEscalates(): void
    {
        [$a] = $this->competingActions();
        (new PlaceQueueService())->reconcile(); // "you're first"

        // Pretend it has been first (and last notified) 8 days ago.
        $this->pdo->prepare("UPDATE action_places_desire SET first_at_queue_at = NOW() - INTERVAL '8 days', last_notification_at = NOW() - INTERVAL '8 days' WHERE action_id = ?")
            ->execute([$a]);
        (new PlaceQueueService())->reconcile();
        (new PlaceQueueService())->reconcile();

        $texts = $this->notificationsOf($this->organizerA['id']);
        $this->assertCount(2, $texts, 'one 7-day reminder, not repeated');
        $this->assertStringContainsString('7 a více dní', $texts[1]);
        $this->assertSame([], $this->notificationsOf($this->admin['id']), 'admins only after 14 days');

        $this->pdo->prepare("UPDATE action_places_desire SET first_at_queue_at = NOW() - INTERVAL '15 days', last_notification_at = NOW() - INTERVAL '8 days' WHERE action_id = ?")
            ->execute([$a]);
        (new PlaceQueueService())->reconcile();

        $adminTexts = $this->notificationsOf($this->admin['id']);
        $this->assertCount(1, $adminTexts);
        $this->assertStringContainsString('14 a více dní', $adminTexts[0]);
    }
}
