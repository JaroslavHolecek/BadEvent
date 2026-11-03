<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\Fixtures;
use Tests\Support\HttpTestCase;

/**
 * A rejected form brings the user back with what they typed and with the invalid fields/sections
 * named — the #form-feedback payload that main.js uses to refill the form, tint the problem red
 * and scroll to it (Controller::failForm → layout/base.php).
 */
final class FormFeedbackTest extends HttpTestCase
{
    /** Nastavení posunu (zpoždění 1 s, délka animace) je na každé stránce, i bez chyby; bez chyby nejsou žádná pole k označení. */
    public function testEveryPageCarriesTheScrollSettings(): void
    {
        $this->loginAs('organizer');

        $feedback = $this->http->get('/actions/create')->formFeedback();

        $this->assertSame(['scroll_delay_ms' => 1000, 'scroll_duration_ms' => 450], $feedback['config']);
        $this->assertArrayNotHasKey('fields', $feedback);
        $this->assertArrayNotHasKey('old', $feedback);
    }

    /** Neplatná nová akce: vrátí se zpět s vyplněnými údaji, chybné pole (Název) je označené a heslo se nikdy neuloží. */
    public function testInvalidActionKeepsTypedValuesAndMarksTheField(): void
    {
        $this->loginAs('organizer');
        $room = Fixtures::place('Sál');

        $response = $this->http->post('/actions', [
            'event_id'        => '0',
            'new_event_name'  => 'Podzim',
            'name'            => '',
            'public_start_at' => '2030-10-10T17:00',
            'capacity'        => '40',
            'note'            => 'Rozepsaná poznámka',
            'action_type_id'  => [Fixtures::dict('action_types', 'Kulturní')],
            'place_state'     => [$room => 'requested'],
            'password'        => 'tajne',
        ]);
        $this->assertTrue($response->isRedirectTo('/actions/create'));

        $feedback = $this->http->get('/actions/create')->formFeedback();

        $this->assertSame('/actions', $feedback['action'], 'identifies the form that was sent');
        $this->assertSame(['name'], $feedback['fields']);
        $this->assertSame('40', $feedback['old']['capacity']);
        $this->assertSame('Rozepsaná poznámka', $feedback['old']['note']);
        $this->assertSame('requested', $feedback['old']['place_state'][$room]);
        $this->assertArrayNotHasKey('password', $feedback['old'], 'password-like fields are never kept');
    }

    /** Chybějící událost (ani vybraná, ani nová) označí pole „Název nové události“. */
    public function testMissingEventMarksNewEventName(): void
    {
        $this->loginAs('organizer');

        $this->http->post('/actions', ['event_id' => '0', 'new_event_name' => '', 'name' => 'Beseda']);

        $this->assertSame(['new_event_name'], $this->http->get('/actions/create')->formFeedback()['fields']);
    }

    /** Údaje se do formuláře vrátí jen jednou – po dalším načtení stránky už nejsou. */
    public function testFeedbackIsShownOnlyOnce(): void
    {
        $this->loginAs('organizer');
        $this->http->post('/actions', ['event_id' => '0', 'new_event_name' => 'X', 'name' => '']);

        $this->assertArrayHasKey('fields', $this->http->get('/actions/create')->formFeedback());
        $this->assertArrayNotHasKey('fields', $this->http->get('/actions/create')->formFeedback());
    }

    /** Ekonomické údaje: chybný řádek se označí přesně (2. řádek příjmů → income_amount[1]), všechny řádky se vrátí a nic se neuloží. */
    public function testEconomicFormMarksTheExactRow(): void
    {
        $action = Fixtures::action(['status' => 'completed']);
        $vstupne = Fixtures::dict('income_types', 'Vstupné');
        $czk     = Fixtures::currencyId('CZK');
        $this->loginAs('administrator');

        $response = $this->http->post("/actions/{$action}/edit-economic", [
            'income_type_id'     => [$vstupne, $vstupne],
            'income_subtype_id'  => ['', ''],
            'income_amount'      => ['100', 'sto'],
            'income_currency_id' => [$czk, $czk],
            'income_note'        => ['první', 'druhý'],
        ]);
        $this->assertTrue($response->isRedirectTo("/actions/{$action}/edit-economic"));

        $page     = $this->http->get("/actions/{$action}/edit-economic");
        $feedback = $page->formFeedback();
        $this->assertSame(['income_amount[1]'], $feedback['fields']);
        $this->assertSame(['100', 'sto'], $feedback['old']['income_amount']);
        $this->assertSame(['první', 'druhý'], $feedback['old']['income_note']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM action_incomes'));
        $this->assertStringContainsString('data-add-row-for="income_type_id[]"', $page->body, 'the restore can add missing rows');
    }

    /** Výdaj bez „za co“ označí pole cost_object_id daného řádku. */
    public function testCostWithoutObjectMarksThatField(): void
    {
        $action = Fixtures::action(['status' => 'completed']);
        $this->loginAs('administrator');

        $this->http->post("/actions/{$action}/edit-economic", [
            'cost_type_id'     => [Fixtures::dict('cost_types', 'DPP')],
            'cost_object_id'   => [''],
            'cost_amount'      => ['800'],
            'cost_currency_id' => [Fixtures::currencyId('CZK')],
        ]);

        $this->assertSame(['cost_object_id[0]'], $this->http->get("/actions/{$action}/edit-economic")->formFeedback()['fields']);
    }

    /** Docházka bez počtu: označí se jen pole Počet, vybraný typ a kategorie zůstanou vyplněné. */
    public function testAttendanceMarksOnlyTheMissingField(): void
    {
        $me     = $this->loginAs('organizer');
        $action = Fixtures::action(['organizers' => [$me['id']], 'status' => 'confirmed']);
        $type   = Fixtures::dict('visitor_types', 'Platící');

        $this->http->post("/actions/{$action}/edit-attendance", [
            'attendance_visitor_type_id'     => $type,
            'attendance_visitor_category_id' => Fixtures::dict('visitor_categories', 'Dospělí'),
            'attendance_visitor_count'       => '',
        ]);

        $feedback = $this->http->get("/actions/{$action}/edit-attendance")->formFeedback();
        $this->assertSame(['attendance_visitor_count'], $feedback['fields']);
        $this->assertSame((string) $type, $feedback['old']['attendance_visitor_type_id']);
    }

    /**
     * Změna stavu bez povinných údajů ukáže na sekci detailu akce, kde se údaje doplní:
     * potvrzení → místnosti, proběhlá → docházka, uzavření → ekonomické údaje.
     */
    public function testFailedTransitionPointsToTheSectionToFill(): void
    {
        $this->loginAs('administrator');
        $cases = [
            ['draft', 'confirmed', 'action-places'],
            ['confirmed', 'completed', 'action-attendance'],
            ['completed', 'closed', 'action-economic'],
        ];

        foreach ($cases as [$from, $to, $section]) {
            $action = Fixtures::action(['status' => $from]);

            $this->http->post("/actions/{$action}/transition/{$to}", ['note' => 'Zkouška']);

            $page = $this->http->get("/actions/{$action}");
            $this->assertSame($from, $this->statusOf($action));
            $this->assertSame([$section], $page->formFeedback()['targets'], "{$from} → {$to}");
            $this->assertSame(1, $page->xpath()->query("//*[@id='{$section}']")->length, "the page has #{$section}");
            $this->assertSame('Zkouška', $page->formFeedback()['old']['note'], 'the typed note comes back');
        }
    }

    /** Ostatní formuláře: prázdný povinný údaj se označí (osoba, událost, místnost, číselník, role). */
    public function testOtherFormsMarkTheRequiredField(): void
    {
        $this->loginAs('administrator');
        $person = Fixtures::user('viewer')['person_id'];
        $event  = Fixtures::event();
        $place  = Fixtures::place('Sál');

        $cases = [
            ['/people', ['full_name' => ''], '/people/create', ['full_name']],
            ["/people/{$person}", ['full_name' => '', 'short_name' => 'ZK'], "/people/{$person}/edit", ['full_name']],
            ["/people/{$person}/contacts", ['contact_type_id' => '', 'value' => '777'], "/people/{$person}", ['contact_type_id']],
            ["/events/{$event}", ['name' => ''], "/events/{$event}/edit", ['name']],
            ["/places/{$place}", ['name' => '', 'capacity' => '30'], "/places/{$place}/edit", ['name']],
            ['/admin/dictionaries/action_types', ['name' => ''], '/admin/dictionaries?type=action_types', ['name']],
            ['/admin/roles', ['name' => 'Nová role', 'code' => 'Špatný Kód'], '/admin/roles', ['code']],
        ];

        foreach ($cases as [$postTo, $data, $page, $fields]) {
            $response = $this->http->post($postTo, $data);
            $this->assertTrue($response->isRedirectTo($page), "{$postTo} → expected {$page}, got " . var_export($response->location(), true));
            $feedback = $this->http->get($page)->formFeedback();
            $this->assertSame($fields, $feedback['fields'] ?? null, $postTo);
            $this->assertSame($postTo, $feedback['action'], $postTo);
        }
    }
}
