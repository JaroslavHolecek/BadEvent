<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ActionWorkflowService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/** Action lifecycle: draft → confirmed → completed → closed (+ not_agreed / cancelled), its guards and side effects. */
final class ActionWorkflowServiceTest extends DatabaseTestCase
{
    private ActionWorkflowService $workflow;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = new ActionWorkflowService();
        $this->admin    = Fixtures::user('administrator');
    }

    public static function transitions(): array
    {
        return [
            'draft'      => ['draft',      ['confirmed', 'not_agreed']],
            'not_agreed' => ['not_agreed', ['draft']],
            'confirmed'  => ['confirmed',  ['completed', 'cancelled', 'draft']],
            'cancelled'  => ['cancelled',  ['confirmed']],
            'completed'  => ['completed',  ['closed', 'confirmed']],
            'closed'     => ['closed',     ['completed']],
        ];
    }

    /** Povolené přechody stavů akce z každého stavu (dopředu + jeden krok zpět). */
    #[DataProvider('transitions')]
    public function testAllowedTransitions(string $from, array $expected): void
    {
        $this->assertSame($expected, $this->workflow->allowedTransitions($from));
    }

    /** Přeskočit stav (Rozjednaná → Uzavřená) nejde ani s vynucením. */
    public function testSkippingAStatusIsRejected(): void
    {
        $action = Fixtures::action();

        $this->expectException(RuntimeException::class);
        $this->workflow->transition($action, 'closed', $this->admin['id'], null, true);
    }

    /** Potvrzení akce vyžaduje zabranou místnost; s ní projde a uloží poznámku do historie. */
    public function testConfirmRequiresAConfirmedRoom(): void
    {
        $action = Fixtures::action();

        try {
            $this->workflow->transition($action, 'confirmed', $this->admin['id']);
            $this->fail('Confirming without a room must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('místnost', $e->getMessage());
        }
        $this->assertSame('draft', $this->statusOf($action));

        Fixtures::confirm($action, Fixtures::place('Sál'));
        $this->workflow->transition($action, 'confirmed', $this->admin['id'], 'domluveno');

        $this->assertSame('confirmed', $this->statusOf($action));
        $this->assertSame('domluveno', $this->scalar(
            'SELECT note FROM action_status_history WHERE action_id = ? ORDER BY id DESC LIMIT 1', [$action]
        ));
    }

    /** Označit akci jako proběhlou lze až s vyplněnou docházkou. */
    public function testCompleteRequiresAttendance(): void
    {
        $action = Fixtures::action(['status' => 'confirmed']);

        $this->assertSame(['Chybí vyplněná docházka.'], $this->workflow->missingRequirements($action, 'confirmed', 'completed'));

        Fixtures::attendance($action, 'Platící', 'Dospělí', 12);
        $this->assertSame([], $this->workflow->missingRequirements($action, 'confirmed', 'completed'));

        $this->workflow->transition($action, 'completed', $this->admin['id']);
        $this->assertSame('completed', $this->statusOf($action));
    }

    /** Uzavřít akci lze až s ekonomickými údaji (příjem nebo náklad). */
    public function testCloseRequiresIncomeOrCost(): void
    {
        $action = Fixtures::action(['status' => 'completed']);
        $this->assertNotSame([], $this->workflow->missingRequirements($action, 'completed', 'closed'));

        Fixtures::cost($action, 'Materiál', 'Občerstvení', '250.00');
        $this->assertSame([], $this->workflow->missingRequirements($action, 'completed', 'closed'));

        $this->workflow->transition($action, 'closed', $this->admin['id']);
        $this->assertSame('closed', $this->statusOf($action));
    }

    /** Vynucený přechod (admin) projde i bez povinných údajů. */
    public function testForceSkipsTheRequiredDataGuard(): void
    {
        $action = Fixtures::action();

        $this->workflow->transition($action, 'confirmed', $this->admin['id'], null, true);

        $this->assertSame('confirmed', $this->statusOf($action));
    }

    /** Krok zpět (Potvrzená → Rozjednaná) nevyžaduje žádné údaje. */
    public function testStepBackIsAllowedWithoutGuards(): void
    {
        $action = Fixtures::action(['status' => 'confirmed']);

        $this->workflow->transition($action, 'draft', $this->admin['id']);

        $this->assertSame('draft', $this->statusOf($action));
    }

    /** Zrušení / Nedojednání akce uvolní všechny její požadované i zabrané místnosti. */
    #[DataProvider('releasingStatuses')]
    public function testCancellingReleasesAllRooms(string $from, string $to): void
    {
        $action = Fixtures::action(['status' => $from]);
        $place  = Fixtures::place('Klubovna');
        Fixtures::request($action, $place);
        Fixtures::confirm($action, $place);

        $this->workflow->transition($action, $to, $this->admin['id']);

        $this->assertSame($to, $this->statusOf($action));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM action_places WHERE action_id = ?', [$action]));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM action_places_desire WHERE action_id = ?', [$action]));
    }

    public static function releasingStatuses(): array
    {
        return [
            'confirmed → cancelled' => ['confirmed', 'cancelled'],
            'draft → not_agreed'    => ['draft', 'not_agreed'],
        ];
    }

    /** Změna stavu se zapíše do auditu a upozorní ostatní organizátory a zástupy, ne autora změny. */
    public function testStatusChangeIsAuditedAndNotifiesOtherOrganizers(): void
    {
        $actor     = Fixtures::user('organizer');
        $colleague = Fixtures::user('organizer');
        $substitute = Fixtures::user('substitute');
        $action    = Fixtures::action(['name' => 'Beseda', 'organizers' => [$actor['id'], $colleague['id']], 'substitutes' => [$substitute['id']]]);
        Fixtures::confirm($action, Fixtures::place('Sál'));

        $this->workflow->transition($action, 'confirmed', $actor['id']);

        $this->assertSame([], $this->notificationsOf($actor['id']), 'the one who made the change is not notified');
        $this->assertSame(['Akce "Beseda" přešla do stavu: Potvrzená.'], $this->notificationsOf($colleague['id']));
        $this->assertSame(['Akce "Beseda" přešla do stavu: Potvrzená.'], $this->notificationsOf($substitute['id']));
        $this->assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'action' AND entity_id = ? AND operation = 'status_change'", [$action]
        ));
    }
}
