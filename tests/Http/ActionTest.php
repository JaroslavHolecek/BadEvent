<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\Fixtures;
use Tests\Support\HttpTestCase;

/** Creating and managing actions through the real forms, including who may do what. */
final class ActionTest extends HttpTestCase
{
    private int $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->room = Fixtures::place('Velký sál', 'VS');
    }

    /** POST data of the "Nová akce" form. */
    private function createForm(array $override = []): array
    {
        return $override + [
            'event_id'         => '0',
            'new_event_name'   => 'Podzimní cyklus',
            'name'             => 'Beseda s autorem',
            'staff_start_at'   => '2030-10-10T16:30',
            'staff_end_at'     => '2030-10-10T19:30',
            'public_start_at'  => '2030-10-10T17:00',
            'public_end_at'    => '2030-10-10T19:00',
            'capacity'         => '40',
            'action_type_id'   => [Fixtures::dict('action_types', 'Kulturní')],
            'place_state'      => [$this->room => 'confirmed'],
            'place_note'       => [$this->room => 'Židle do kruhu'],
        ];
    }

    private function createdActionId(\Tests\Support\HttpResponse $response): int
    {
        $this->assertMatchesRegularExpression('#^/actions/\d+$#', (string) $response->location(), 'expected redirect to the new action');
        return (int) substr((string) $response->location(), strlen('/actions/'));
    }

    /** Organizátor založí akci formulářem: vznikne událost, akce ve stavu Rozjednaná, autor je organizátor, volná místnost je zabraná s poznámkou. */
    public function testOrganizerCreatesAction(): void
    {
        $organizer = $this->loginAs('organizer');

        $id = $this->createdActionId($this->http->post('/actions', $this->createForm()));

        $action = $this->pdo->query("SELECT a.*, e.name AS event_name FROM actions a JOIN events e ON e.id = a.event_id WHERE a.id = {$id}")->fetch();
        $this->assertSame('Beseda s autorem', $action['name']);
        $this->assertSame('Podzimní cyklus', $action['event_name'], 'a new event is created from new_event_name');
        $this->assertSame('2030-10-10 17:00:00', $action['public_start_at']);
        $this->assertSame(40, (int) $action['capacity']);
        $this->assertSame('draft', $this->statusOf($id));
        $this->assertSame([$organizer['id']], array_map('intval', $this->pdo->query("SELECT user_id FROM action_organizers WHERE action_id = {$id}")->fetchAll(\PDO::FETCH_COLUMN)), 'creator becomes the organizer');
        $this->assertSame('Židle do kruhu', $this->scalar('SELECT note FROM action_places WHERE action_id = ? AND place_id = ?', [$id, $this->room]), 'free room confirmed right away, note carried over');

        $detail = $this->http->get('/actions/' . $id);
        $this->assertOk($detail);
        $this->assertStringContainsString('Beseda s autorem', $detail->text());
    }

    /** Místnost, o kterou už dříve požádal někdo jiný, se při založení akce jen zařadí do fronty. */
    public function testRoomTakenByEarlierRequestIsOnlyQueued(): void
    {
        $competitor = Fixtures::action(['public_start_at' => '2030-10-10 18:00:00']);
        Fixtures::request($competitor, $this->room, Fixtures::at('-1 day'));
        $this->loginAs('organizer');

        $id = $this->createdActionId($this->http->post('/actions', $this->createForm()));

        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM action_places_desire WHERE action_id = ?', [$id]));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM action_places WHERE action_id = ?', [$id]));
    }

    /**
     * Autor nové akce, která je s požadovanou místností první ve frontě, dostane upozornění „jste první“
     * (fronta se kontroluje až po uložení organizátorů — regrese 4. 11. 2026).
     */
    public function testCreatorOfNewActionIsNotifiedAboutFirstPlaceInQueue(): void
    {
        $organizer = $this->loginAs('organizer');

        $this->createdActionId($this->http->post('/actions', $this->createForm(['place_state' => [$this->room => 'requested']])));

        $texts = $this->notificationsOf($organizer['id']);
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('je první ve frontě na místnost "Velký sál"', $texts[0]);
    }

    /** Organizátor odebraný při úpravě akce už upozornění z této úpravy nedostane, nově přidaný ano (regrese 4. 11. 2026). */
    public function testQueueNotificationAfterEditGoesToTheNewOrganizersOnly(): void
    {
        $this->loginAs('administrator');
        $oldOrganizer = Fixtures::user('organizer');
        $newOrganizer = Fixtures::user('organizer');
        $action  = Fixtures::action(['organizers' => [$oldOrganizer['id']]]);
        $eventId = (int) $this->scalar('SELECT event_id FROM actions WHERE id = ?', [$action]);

        $response = $this->http->post("/actions/{$action}", $this->createForm([
            'event_id'     => (string) $eventId,
            'place_state'  => [$this->room => 'requested'],
            'organizer_id' => [$newOrganizer['id']],
        ]));

        $this->assertTrue($response->isRedirectTo("/actions/{$action}"), 'edit saved');
        $this->assertSame([], $this->notificationsOf($oldOrganizer['id']), 'removed organizer');
        $this->assertCount(1, $this->notificationsOf($newOrganizer['id']), 'new organizer');
    }

    /** Neplatný formulář (bez názvu, nesmyslný rok, bez události) nic neuloží. */
    public function testInvalidActionIsNotSaved(): void
    {
        $this->loginAs('organizer');

        $this->assertTrue($this->http->post('/actions', $this->createForm(['name' => '  ']))->isRedirectTo('/actions/create'));
        $this->assertTrue($this->http->post('/actions', $this->createForm(['staff_start_at' => '0026-10-10T16:30']))->isRedirectTo('/actions/create'));
        $this->assertTrue($this->http->post('/actions', $this->createForm(['new_event_name' => '']))->isRedirectTo('/actions/create'));

        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM actions'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM events'), 'nothing half-saved');
    }

    /** Role bez oprávnění „Vytvořit akci“ akci nezaloží ani přímým POSTem. */
    public function testRoleWithoutActionCreateCannotCreate(): void
    {
        $this->loginAs('viewer');

        $this->assertDenied($this->http->post('/actions', $this->createForm()));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM actions'));
    }

    /** Organizátor může měnit místnosti jen u svých akcí, u cizích ne. */
    public function testOrganizerCanEditOnlyOwnActions(): void
    {
        $me    = $this->loginAs('organizer');
        $other = Fixtures::user('organizer');
        $mine  = Fixtures::action(['organizers' => [$me['id']]]);
        $their = Fixtures::action(['organizers' => [$other['id']]]);

        $this->assertTrue($this->http->post("/actions/{$mine}/places", ['place_state' => [$this->room => 'requested']])->isRedirectTo("/actions/{$mine}/edit"));
        $this->assertTrue($this->http->post("/actions/{$their}/places", ['place_state' => [$this->room => 'requested']])->isRedirectTo("/actions/{$their}"));

        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM action_places_desire WHERE action_id = ?', [$mine]));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM action_places_desire WHERE action_id = ?', [$their]));
    }

    /** Uzavřenou akci organizátor už upravit nemůže. */
    public function testClosedActionIsFrozenForOrganizer(): void
    {
        $me     = $this->loginAs('organizer');
        $closed = Fixtures::action(['organizers' => [$me['id']], 'status' => 'closed']);

        $this->http->post("/actions/{$closed}/places", ['place_state' => [$this->room => 'requested']]);

        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM action_places_desire WHERE action_id = ?', [$closed]));
    }

    /** Zapsání docházky formulářem se uloží. */
    public function testAttendanceIsRecorded(): void
    {
        $me     = $this->loginAs('organizer');
        $action = Fixtures::action(['organizers' => [$me['id']], 'status' => 'confirmed']);

        $this->http->post("/actions/{$action}/edit-attendance", [
            'attendance_visitor_type_id'     => Fixtures::dict('visitor_types', 'Platící'),
            'attendance_visitor_category_id' => Fixtures::dict('visitor_categories', 'Dospělí'),
            'attendance_visitor_count'       => '23',
        ]);

        $this->assertSame(23, (int) $this->scalar('SELECT SUM(visitor_count) FROM action_attendance WHERE action_id = ?', [$action]));
    }

    /** Organizátor potvrdí akci jen se zabranou místností, vynucení ani zrušení akce provést nemůže. */
    public function testOrganizerMovesActionThroughWorkflowButCannotForceIt(): void
    {
        $me     = $this->loginAs('organizer');
        $action = Fixtures::action(['organizers' => [$me['id']]]);

        $this->http->post("/actions/{$action}/transition/confirmed", ['force' => '1']);
        $this->assertSame('draft', $this->statusOf($action), 'no room yet, and force is admin-only');

        Fixtures::confirm($action, $this->room);
        $this->http->post("/actions/{$action}/transition/confirmed");
        $this->assertSame('confirmed', $this->statusOf($action));

        $this->http->post("/actions/{$action}/transition/cancelled");
        $this->assertSame('confirmed', $this->statusOf($action), 'organizer role has no action.cancel');
    }

    /** Administrátor může přechod vynutit (force=1), bez vynucení platí kontrola i pro něj. */
    public function testAdministratorCanForceTransition(): void
    {
        $this->loginAs('administrator');
        $action = Fixtures::action();

        $this->http->post("/actions/{$action}/transition/confirmed");
        $this->assertSame('draft', $this->statusOf($action), 'without force the guard applies to admins too');

        $this->http->post("/actions/{$action}/transition/confirmed", ['force' => '1']);
        $this->assertSame('confirmed', $this->statusOf($action));
    }

    /** Zástup organizátora nemůže měnit stav akce. */
    public function testSubstituteCannotChangeStatus(): void
    {
        $me     = $this->loginAs('substitute');
        $action = Fixtures::action(['substitutes' => [$me['id']]]);
        Fixtures::confirm($action, $this->room);

        $this->http->post("/actions/{$action}/transition/confirmed");

        $this->assertSame('draft', $this->statusOf($action));
    }

    /** Ekonomické údaje uloží jen role s oprávněním (admin), organizátor ne. */
    public function testEconomicDataOnlyWithPermission(): void
    {
        $action = Fixtures::action(['status' => 'completed']);
        $form   = [
            'income_type_id'     => [Fixtures::dict('income_types', 'Vstupné')],
            'income_subtype_id'  => [''],
            'income_amount'      => ['1250.50'],
            'income_currency_id' => [Fixtures::currencyId('CZK')],
            'cost_type_id'       => [Fixtures::dict('cost_types', 'DPP')],
            'cost_object_id'     => [Fixtures::dict('cost_objects', 'Honorář')],
            'cost_amount'        => ['800'],
            'cost_currency_id'   => [Fixtures::currencyId('CZK')],
        ];

        $this->loginAs('organizer');
        $this->assertDenied($this->http->post("/actions/{$action}/edit-economic", $form));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM action_incomes'));

        $this->loginAs('administrator');
        $this->assertTrue($this->http->post("/actions/{$action}/edit-economic", $form)->isRedirectTo("/actions/{$action}"));
        $this->assertSame('1250.50', $this->scalar('SELECT amount FROM action_incomes WHERE action_id = ?', [$action]));
        $this->assertSame('800.00', $this->scalar('SELECT amount FROM action_costs WHERE action_id = ?', [$action]));
    }

    /** Organizátor smaže svou Rozjednanou akci, Potvrzenou ne. */
    public function testOrganizerDeletesOwnDraftButNotConfirmedAction(): void
    {
        $me        = $this->loginAs('organizer');
        $draft     = Fixtures::action(['organizers' => [$me['id']]]);
        $confirmed = Fixtures::action(['organizers' => [$me['id']], 'status' => 'confirmed']);

        $this->http->post("/actions/{$draft}/delete");
        $this->http->post("/actions/{$confirmed}/delete");

        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM actions WHERE id = ?', [$draft]));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM actions WHERE id = ?', [$confirmed]));
    }
}
