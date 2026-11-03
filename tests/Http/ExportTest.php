<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\Fixtures;
use Tests\Support\HttpTestCase;
use Tests\Support\StatisticsDataset;

/** Exports as the administrator downloads/prints them — values and formatting included. */
final class ExportTest extends HttpTestCase
{
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ids = StatisticsDataset::build();
    }

    private const RANGE = ['date_from' => StatisticsDataset::FROM, 'date_to' => StatisticsDataset::TO];

    /** Exporty jsou jen pro administrátora; organizátor je přesměrován a nedostane žádná data. */
    public function testOnlyAdministratorCanExport(): void
    {
        $this->loginAs('organizer');

        foreach (['/export', '/export/print', '/export/excel', '/export/statistika/excel', '/export/rooms/excel', '/export/maintenance/excel', '/export/media-team/print', '/export/weekly/print'] as $path) {
            $response = $this->http->get($path, self::RANGE);
            $this->assertDenied($response, $path);
            $this->assertStringNotContainsString('Koncert', $response->body);
        }
    }

    /** Obecný export: správné místnosti, organizátoři, návštěvnost a součty příjmů/výdajů po měnách; filtr období. */
    public function testGeneralExportComputesPerActionTotals(): void
    {
        $alice = Fixtures::user('organizer', 'Alice Nováková');
        $this->pdo->prepare('INSERT INTO action_organizers (action_id, user_id) VALUES (?, ?)')->execute([$this->ids['K1'], $alice['id']]);
        Fixtures::confirm($this->ids['K1'], Fixtures::place('Sál'));
        $this->loginAs('administrator');

        $page = $this->http->get('/export/print', self::RANGE + ['column' => ['places', 'organizers', 'attendance', 'income_total', 'cost_total', 'status']]);
        $this->assertOk($page);

        $row = $page->tableRow('Koncert');
        $this->assertNotNull($row, 'Koncert row present');
        $this->assertSame('Sál', $row['Zabrané místo']);
        $this->assertSame('Alice Nováková', $row['Organizátor']);
        $this->assertSame('30', $row['Návštěvnost celkem']);
        $this->assertSame('6 500,00 Kč, 10,00 €', $row['Příjmy celkem'], 'per currency: 1500 + 5000 CZK, 10 EUR');
        $this->assertSame('3 500,00 Kč', $row['Výdaje celkem']);
        $this->assertSame('—', $page->tableRow('Výstava fotografií')['Příjmy celkem']);
        $this->assertNull($page->tableRow('Příští rok'), 'outside the date range');
    }

    /** Excel export se stáhne jako příloha a obsahuje stejné řádky a hodnoty. */
    public function testExcelExportHasTheSameRows(): void
    {
        $this->loginAs('administrator');

        $response = $this->http->get('/export/excel', self::RANGE + ['column' => ['attendance']]);

        $this->assertOk($response);
        $this->assertStringContainsString('application/vnd.ms-excel', (string) $response->header('Content-Type'));
        $this->assertStringContainsString('attachment; filename="export_akci_', (string) $response->header('Content-Disposition'));

        $rows = $response->spreadsheetRows();
        $this->assertSame(['Název akce', 'Návštěvnost celkem'], $rows[0]);
        $this->assertContains(['Koncert', '30'], $rows);
        $this->assertContains(['Kurz online', '15'], $rows);
        $this->assertNotContains(['Příští rok', '50'], $rows);
    }

    /** Statistika (NIPOS) – každý řádek má ručně spočítanou správnou hodnotu. */
    public function testStatisticsReport(): void
    {
        $this->loginAs('administrator');

        $page = $this->http->get('/export/statistika/print', self::RANGE);
        $this->assertOk($page);

        $value = fn(string $code) => $page->tableRow($code)['Hodnota'] ?? null;
        $this->assertSame('3', $value('01A.1'));
        $this->assertSame('0', $value('01A.1.1'), 'the only online action (V1) is Vzdělávací, not Kulturní/komunitní');
        $this->assertSame('1', $value('01A.1.2'));
        $this->assertSame('2', $value('01A.2'));
        $this->assertSame('4:30:00', $value('01A.2.1'));
        $this->assertSame('1', $value('01A.2.2'));
        $this->assertSame('1:30:00', $value('01A.2.3'));
        $this->assertSame('1', $value('01A.2.4'));
        $this->assertSame('1', $value('01A.3'));
        $this->assertSame('57', $value('01.B'));
        $this->assertSame('42', $value('01.B.1'));
        $this->assertSame('25', $value('01.B.1.1'));
        $this->assertSame('20', $value('01.B.2'));
        $this->assertSame('15', $value('01.B.2.2a'));
        $this->assertSame('7', $value('01.B.3'));
        $this->assertSame('1 700,00', $value('01.C.1'));
        $this->assertSame('1 500,00', $value('01.C.1.1'));
        $this->assertSame('3 000,00', $value('01.C.2'));
        $this->assertSame('500,00', $value('01.C.3'));
    }

    /** Statistika (Excel) – celá tabulka: každý řádek s kódem, názvem a ručně spočítanou hodnotou (viz StatisticsDataset). */
    public function testStatisticsExcelHasEveryValue(): void
    {
        $this->loginAs('administrator');

        $response = $this->http->get('/export/statistika/excel', self::RANGE);

        $this->assertOk($response);
        $this->assertStringContainsString('attachment; filename="statistika_', (string) $response->header('Content-Disposition'));
        $this->assertSame(self::EXPECTED_STATISTICS, $response->spreadsheetRows());
    }

    /** Statistika pro tisk ukazuje přesně stejné řádky a hodnoty jako Excel. */
    public function testStatisticsPrintMatchesExcel(): void
    {
        $this->loginAs('administrator');

        $this->assertSame(self::EXPECTED_STATISTICS, $this->http->get('/export/statistika/print', self::RANGE)->tableRows());
    }

    /** Statistika už nemá vývojový sloupec „Co je potřeba doplnit“; chybí-li položka číselníku, hodnota zůstane prázdná (ne 0). */
    public function testStatisticsLeavesValueEmptyWhenDictionaryEntryIsMissing(): void
    {
        $this->loginAs('administrator');

        $page = $this->http->get('/export/statistika/print', self::RANGE);

        $this->assertStringNotContainsString('Co je potřeba doplnit', $page->body);
        $this->assertSame('', $page->tableRow('01.B.2.2b')['Hodnota'], 'no visitor category „Žáci 2. st. ZŠ“');
    }

    /** Statistika bez zadaného období počítá celý aktuální rok (data z roku 2030 se tedy nezapočtou). */
    public function testStatisticsDefaultsToTheCurrentYear(): void
    {
        $this->loginAs('administrator');

        $page = $this->http->get('/export/statistika/print');

        $this->assertStringContainsString('1. 1. ' . date('Y'), $page->text());
        $this->assertSame('0', $page->tableRow('01.B')['Hodnota']);
    }

    /**
     * Expected "Statistika" for StatisticsDataset (2030):
     * Kulturní/komunitní = K1, V2, NM; Vzdělávací = V1 (online, ICT, 1.5 h), V2 (3 h); Výstava = X;
     * attendance K1 30 (20 paying), V1 15 (Žáci 1. st. ZŠ), V2 5 paying, NM 7 (not main organizer);
     * CZK Vstupné K1 1500 + V1 200 (K1's 10 EUR and Dotace don't count); Honorář 3000, Propagace 500.
     */
    private const EXPECTED_STATISTICS = [
        ['Kód', 'Název', 'Hodnota'],
        ['01A.1', 'Kulturní, komunitní a volnočasové akce pro veřejnost (besedy, výstavy, aj.)', '3'],
        ['01A.1.1', '-- z toho online (virtuální) kulturní, komunitní a volnočasové akce pro veřejnost', '0'],
        ['01A.1.2', '-- z toho počet výstav', '1'],
        ['01A.2', 'Vzdělávací akce pro veřejnost (semináře, kurzy, aj.)', '2'],
        ['01A.2.1', '-- počet hodin vzdělávacích akcí pro veřejnost', '4:30:00'],
        ['01A.2.2', '-- počet online (virtuálních) vzdělávacích akcí pro veřejnost', '1'],
        ['01A.2.3', '-- počet hodin online (virtuálních) vzdělávacích akcí pro veřejnost', '1:30:00'],
        ['01A.2.4', '-- vzdělávací akce v oblasti ICT (inform. a komunikačních technologií)', '1'],
        ['01A.2.5', '-- počet hodin vzdělávacích akcí v oblasti ICT (inform. a komunikačních technologií)', '1:30:00'],
        ['01A.3', 'Ostatní akce, kde knihovna není hlavním pořadatelem', '1'],
        ['', '01.B – Návštěvnost akcí knihovny', ''],
        ['01.B', 'Návštěvníci všech akcí knihovny', '57'],
        ['01.B.1', '-- počet návštěvníků kulturních akcí (celkem)', '42'],
        ['01.B.1.1', '-- počet platících návštěvníků kulturních akcí (celkem)', '25'],
        ['01.B.2', '-- počet návštěvníků vzdělávacích akcí', '20'],
        ['01.B.2.1', '-- počet platících návštěvníků vzdělávacích akcí (celkem)', '5'],
        ['01.B.2.2a', '-- struktura návštěvníků vzdělávacích akcí: počet návštěvníků z řad žáků 1. stupně ZŠ', '15'],
        ['01.B.2.2b', '-- struktura návštěvníků vzdělávacích akcí: počet návštěvníků z řad žáků 2. stupně ZŠ (nebo ekvivalentních ročníků SŠ)', ''],
        ['01.B.2.2c', '-- struktura návštěvníků vzdělávacích akcí: počet návštěvníků z řad studentů SŠ', ''],
        ['01.B.2.2d', '-- struktura návštěvníků vzdělávacích akcí: počet návštěvníků z řad žáků mateřských škol', ''],
        ['01.B.3', '-- počet návštěvníků ostatních akcí, kde knihovna není hlavním pořadatelem', '7'],
        ['', '01.C – Ekonomika akcí', ''],
        ['01.C.1', '-- celkové příjmy ze vstupného na akce pořádané knihovnou', '1 700,00'],
        ['01.C.1.1', '-- příjmy ze vstupného - kulturní akce', '1 500,00'],
        ['01.C.1.2', '-- příjmy ze vstupného - vzdělávací akce', '200,00'],
        ['01.C.1.3', '-- příjmy ze vstupného - komunitní / společenské akce', '0,00'],
        ['01.C.2', '-- výdaje na zajištění programu knihovny', '3 000,00'],
        ['01.C.3', '-- výdaje na propagaci programu knihovny', '500,00'],
    ];

    /**
     * Každý export pro tisk má logo organizace v pravém horním rohu každého listu s nadpisem – jako první prvek
     * nadpisu (plovoucí vpravo, nadpis pokračuje pod ním); u přehledu místností u nadpisu každé místnosti,
     * u plánu akcí u každého týdne.
     */
    public function testEveryPrintExportHasTheLogoInItsHeadings(): void
    {
        Fixtures::confirm($this->ids['K1'], Fixtures::place('Sál'));
        Fixtures::confirm($this->ids['V1'], Fixtures::place('Učebna'));
        $this->pdo->exec("UPDATE actions SET photograph = TRUE WHERE id = {$this->ids['K1']}");
        $this->loginAs('administrator');

        $expectedHeadings = [
            '/export/print'             => 1,
            '/export/statistika/print'  => 1,
            '/export/rooms/print'       => 2,   // a sheet per room
            '/export/media-team/print'  => 1,
            '/export/maintenance/print' => 1,
            '/export/weekly/print'      => 53,  // a sheet per week of 2030 (1. 1. is a Tuesday, 31. 12. a Tuesday)
        ];
        foreach ($expectedHeadings as $path => $count) {
            $page  = $this->http->get($path, self::RANGE);
            $xpath = $page->xpath();
            $this->assertOk($page, $path);
            $this->assertSame($count, $xpath->query('//h1[@class="print-sheet-title"]')->length, $path);
            $this->assertSame($count, $xpath->query('//h1[@class="print-sheet-title"]/*[1][self::img][@class="print-logo"][contains(@src, "logo-min.svg")]')->length, $path);
            $this->assertSame($count, $xpath->query('//img[@class="print-logo"]')->length, "{$path}: no other logo");
        }
    }

    /**
     * Plán akcí: rozsah středa–úterý dá dva listy (St–Ne a Po–Út), každý se všemi 7 dny ve stejném pořadí;
     * dny mimo rozsah mají text „Tento den není v zadaném rozsahu“, dny bez akce „V tento den není žádná akce“; akce má čas pro veřejnost, název, čas pro personál, místnosti a zkratky organizátorů;
     * zrušené akce ani akce mimo rozsah se nevypíší a vícedenní akce je u každého svého dne.
     */
    public function testWeeklyPlanHasOneSheetPerWeek(): void
    {
        $alice = Fixtures::user('organizer', 'Alice Nováková', 'AN');
        $bob   = Fixtures::user('organizer', 'Bob Dvořák', 'BD');
        $beseda = Fixtures::action(['name' => 'Beseda s autorem', 'organizers' => [$alice['id'], $bob['id']],
            'public_start_at' => '2030-03-06 09:30:00', 'public_end_at' => '2030-03-06 11:00:00']);   // Wednesday
        Fixtures::confirm($beseda, Fixtures::place('Sál'));
        Fixtures::confirm($beseda, Fixtures::place('Velká lektorna'));
        Fixtures::action(['name' => 'Italština', 'status' => 'confirmed',
            'public_start_at' => '2030-03-11 14:30:00', 'public_end_at' => '2030-03-11 16:30:00']);  // Monday
        Fixtures::action(['name' => 'Výstava přes víkend',
            'public_start_at' => '2030-03-09 10:00:00', 'public_end_at' => '2030-03-11 18:00:00']);  // Sat → Mon
        Fixtures::action(['name' => 'Zrušená beseda', 'status' => 'cancelled', 'public_start_at' => '2030-03-07 10:00:00']);
        Fixtures::action(['name' => 'Pondělí před rozsahem', 'public_start_at' => '2030-03-04 10:00:00']);
        Fixtures::action(['name' => 'Po rozsahu', 'public_start_at' => '2030-03-13 10:00:00']);
        $this->loginAs('administrator');

        $page  = $this->http->get('/export/weekly/print', ['date_from' => '2030-03-06', 'date_to' => '2030-03-12']);
        $this->assertOk($page);
        $xpath = $page->xpath();
        $text  = fn(\DOMNode $n) => \Tests\Support\HttpResponse::nodeText($n);

        $sheets = $xpath->query('//section[contains(@class, "week-sheet")]');
        $this->assertSame(2, $sheets->length);
        $this->assertSame('Plán akcí pro 6. 3. – 10. 3. 2030', $text($xpath->query('.//h1', $sheets[0])[0]));
        $this->assertSame('Plán akcí pro 11. 3. – 12. 3. 2030', $text($xpath->query('.//h1', $sheets[1])[0]));

        $this->assertSame(['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'],
            array_map($text, iterator_to_array($xpath->query('.//thead/tr/th/text()', $sheets[1]))), 'always all 7 days, Monday first');
        $this->assertSame(['4. 3.', '5. 3.', '6. 3.', '7. 3.', '8. 3.', '9. 3.', '10. 3.'],
            array_map($text, iterator_to_array($xpath->query('.//thead/tr/th/span', $sheets[0]))), 'dates without the year');

        // Day columns: [sheet][day 0 = Monday] => text
        $days = fn(int $sheet) => array_map($text, iterator_to_array($xpath->query('.//tbody/tr/td', $sheets[$sheet])));
        $first  = $days(0);
        $second = $days(1);
        $this->assertCount(7, $first);
        $this->assertCount(7, $second);

        $this->assertSame('Tento den není v zadaném rozsahu', $first[0], 'Monday 4. 3. is before the range — even though an action takes place');
        $this->assertSame('Tento den není v zadaném rozsahu', $first[1]);
        $this->assertStringStartsWith('9:30–11:00 Beseda s autorem Personál: 9:00–11:30 Sál, Velká lektorna Org.: ', $first[2]);
        $this->assertMatchesRegularExpression('/Org\.: (AN, BD|BD, AN)$/', $first[2], 'abbreviations only, never full names');
        $this->assertSame('V tento den není žádná akce', $first[3], 'the cancelled action is left out');
        $this->assertSame('V tento den není žádná akce', $first[4]);
        $this->assertStringContainsString('Výstava přes víkend', $first[5]);
        $this->assertStringContainsString('10:00–11. 3. 18:00', $first[5], 'an end on another day carries its date');
        $this->assertStringContainsString('Výstava přes víkend', $first[6]);

        $this->assertStringContainsString('9. 3. 10:00–18:00 Výstava přes víkend', $second[0]);
        $this->assertStringContainsString('14:30–16:30 Italština', $second[0]);
        $this->assertStringContainsString('Org.: —', $second[0]);
        $this->assertSame('V tento den není žádná akce', $second[1], 'Tuesday 12. 3. is the last day of the range');
        $this->assertSame(array_fill(0, 5, 'Tento den není v zadaném rozsahu'), array_slice($second, 2), 'Wednesday 13. 3. onwards is after the range');
        $this->assertSame(2, $xpath->query('.//td[contains(@class, "out-of-range")]', $sheets[0])->length, 'Mon + Tue greyed out');
        $this->assertSame(5, $xpath->query('.//td[contains(@class, "out-of-range")]', $sheets[1])->length, 'Wed–Sun greyed out');
    }

    /** Přehled místností pro tisk: nadpis listu je „Akce v místnosti“ a název místnosti (se zkratkou) na druhém řádku nadpisu; datum a čas od–do se zalomí na dva řádky za oddělovačem. */
    public function testRoomsPrintHeadingHasTheRoomOnItsOwnLine(): void
    {
        Fixtures::confirm($this->ids['K1'], Fixtures::place('Sál', 'S1'));
        $this->loginAs('administrator');

        $page  = $this->http->get('/export/rooms/print', self::RANGE);
        $xpath = $page->xpath();
        $title = $xpath->query('//h1[@class="print-sheet-title"]')[0];

        $this->assertStringContainsString("10. 3. 2030 17:00 –<br />\n10. 3. 2030 19:00", $page->body, 'date range broken onto two lines after the separator');

        $this->assertSame('Akce v místnosti Sál (S1)', \Tests\Support\HttpResponse::nodeText($title));
        $this->assertSame('Sál (S1)', \Tests\Support\HttpResponse::nodeText($xpath->query('.//span[@class="print-sheet-subtitle"]', $title)[0]));
    }

    /** Export pro mediální tým a pro údržbu: každý organizátor je na samostatném řádku (za čárkou zalomení) v tisku i v Excelu; přehled místností má zkratky dál na jednom řádku. */
    public function testMediaTeamAndMaintenanceListOneOrganizerPerLine(): void
    {
        $repo = new \App\Repositories\ActionRepository();
        foreach (['Alice Nováková' => 'AN', 'Bob Dvořák' => 'BD'] as $name => $abbr) {
            $this->pdo->prepare('INSERT INTO action_organizers (action_id, user_id) VALUES (?, ?)')
                ->execute([$this->ids['K1'], Fixtures::user('organizer', $name, $abbr)['id']]);
        }
        $order = array_column($repo->getOrganizers($this->ids['K1']), 'full_name');
        Fixtures::confirm($this->ids['K1'], Fixtures::place('Sál'));
        $this->pdo->exec("UPDATE actions SET photograph = TRUE WHERE id = {$this->ids['K1']}");
        $this->loginAs('administrator');

        $expected = implode(",\n", $order);   // e.g. "Alice Nováková,\nBob Dvořák"
        $this->assertSame($expected, $this->http->get('/export/media-team/excel', self::RANGE)->spreadsheetRows()[1][4]);
        $this->assertSame($expected, $this->http->get('/export/maintenance/excel', self::RANGE)->spreadsheetRows()[1][3]);
        $this->assertStringContainsString(implode(",<br />\n", $order), $this->http->get('/export/media-team/print', self::RANGE)->body);
        $this->assertStringContainsString(implode(",<br />\n", $order), $this->http->get('/export/maintenance/print', self::RANGE)->body);

        $rooms = $this->http->get('/export/rooms/excel', self::RANGE)->spreadsheetRows()[1][4];
        $this->assertStringNotContainsString("\n", $rooms, 'rooms export keeps abbreviations on one line');
        $this->assertMatchesRegularExpression('/^(AN, BD|BD, AN)$/', $rooms);
    }

    /** Plán akcí bez zadaného období ukáže jeden list s aktuálním týdnem (pondělí–neděle). */
    public function testWeeklyPlanDefaultsToTheCurrentWeek(): void
    {
        $this->loginAs('administrator');

        $page = $this->http->get('/export/weekly/print');

        $monday = new \DateTimeImmutable('monday this week');
        $sunday = $monday->modify('+6 days');
        $this->assertSame(1, $page->xpath()->query('//section[contains(@class, "week-sheet")]')->length);
        $this->assertStringContainsString('Plán akcí pro ' . $monday->format('j. n.'), $page->text());
        $this->assertStringContainsString($sunday->format('j. n. Y'), $page->text());
    }

    /** Přehled místností: jen zabrané místnosti, časy a pouze zkratky organizátorů (veřejný dokument). */
    public function testRoomsExportShowsOnlyAbbreviationsOfOrganizers(): void
    {
        $alice = Fixtures::user('organizer', 'Alice Nováková', 'AN');
        $this->pdo->prepare('INSERT INTO action_organizers (action_id, user_id) VALUES (?, ?)')->execute([$this->ids['K1'], $alice['id']]);
        Fixtures::confirm($this->ids['K1'], Fixtures::place('Sál', 'S1'));
        Fixtures::request($this->ids['V1'], Fixtures::place('Učebna'));  // only requested — not in the rooms export
        $this->loginAs('administrator');

        $rows = $this->http->get('/export/rooms/excel', self::RANGE)->spreadsheetRows();

        $this->assertSame([
            ['Místnost', 'Název akce', 'Datum a čas akce', 'Místnost blokována', 'Organizátoři'],
            ['Sál (S1)', 'Koncert', "10. 3. 2030 17:00 –\n10. 3. 2030 19:00", "10. 3. 2030 16:30 –\n10. 3. 2030 19:30", 'AN'],
        ], $rows);
    }

    /** Export pro údržbu obsahuje poznámku místnosti i poznámku k místnosti u akce. */
    public function testMaintenanceExportCarriesRoomNotes(): void
    {
        $room = Fixtures::place('Sál', null, 'Klíč na vrátnici');
        Fixtures::confirm($this->ids['K1'], $room, 'Postavit pódium');
        $this->loginAs('administrator');

        $rows = $this->http->get('/export/maintenance/excel', self::RANGE)->spreadsheetRows();

        $this->assertCount(2, $rows, 'header + the only action with a confirmed room');
        $this->assertSame('Koncert', $rows[1][0]);
        $this->assertSame('Sál – Klíč na vrátnici; Postavit pódium', $rows[1][4]);
    }
}
