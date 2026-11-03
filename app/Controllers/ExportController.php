<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\View;
use App\Repositories\ActionRepository;
use App\Repositories\DictionaryRepository;
use App\Repositories\FinanceRepository;
use App\Repositories\PlaceRepository;
use App\Repositories\StatisticsRepository;
use App\Repositories\UserRepository;

final class ExportController extends Controller
{
    /**
     * Optional per-action columns offered for the print export, in display order. "Název akce"
     * isn't listed here — it's always shown, since a row with no name would be meaningless.
     * income_total/cost_total are stripped from what's offered when the user can't view economic data.
     */
    private const COLUMNS = [
        'event_name'   => 'Událost',
        'date_range'   => 'Datum (od–do)',
        'staff_range'  => 'Blokace pro personál (od–do)',
        'action_types' => 'Typ akce',
        'action_topics' => 'Téma akce',
        'action_formats' => 'Provedení akce',
        'visitor_categories' => 'Kategorie návštěvníků',
        'status'       => 'Stav',
        'places'       => 'Zabrané místo',
        'organizers'   => 'Organizátor',
        'substitutes'  => 'Zástup',
        'staff'        => 'Pomocníci',
        'capacity'     => 'Kapacita',
        'attendance'   => 'Návštěvnost celkem',
        'income_total' => 'Příjmy celkem',
        'cost_total'   => 'Výdaje celkem',
        'photograph'   => 'Mediální tým',
        'photograph_note' => 'Poznámka pro mediální tým',
        'not_main_organizer' => 'Nejsme hlavní pořadatel',
        'note'         => 'Poznámka',
    ];

    public function index(Request $request, array $params): void
    {
        $this->requireAdmin();

        View::renderWithLayout('export/index', [
            'title'             => 'Export',
            'actionTypes'       => (new DictionaryRepository())->listActive('action_types'),
            'visitorCategories' => (new DictionaryRepository())->listActive('visitor_categories'),
            'organizers'        => (new \App\Repositories\UserRepository())->listByRoleCode('organizer'),
            'statuses'          => (new ActionRepository())->listStatuses(),
            'columns'           => $this->availableColumns(),
            'flash'             => $this->getFlash(),
        ]);
    }

    public function print(Request $request, array $params): void
    {
        $this->requireAdmin();

        echo View::render('export/print', [
            'title' => 'Export akcí',
        ] + $this->resolveExport($request));
    }

    /**
     * Downloads the same filtered rows as a spreadsheet Excel opens directly on double-click,
     * with no delimiter/encoding to guess (unlike CSV, whose delimiter detection depends on the
     * reader's regional settings) — see buildSpreadsheetXml().
     */
    public function excel(Request $request, array $params): void
    {
        $this->requireAdmin();

        $data = $this->resolveExport($request);

        $headerLabels = array_merge(['Název akce'], array_values($data['columns']));
        $dataRows = array_map(function (array $r) use ($data): array {
            $row = [$r['name']];
            foreach (array_keys($data['columns']) as $key) {
                $row[] = (string) ($r[$key] ?? '—');
            }
            return $row;
        }, $data['rows']);

        $this->sendSpreadsheet('export_akci', $headerLabels, $dataRows);
    }

    /**
     * Builds a minimal "Excel 2003 XML Spreadsheet" (SpreadsheetML) document — every cell's
     * column is explicit in the markup, so unlike CSV there's no delimiter or encoding for the
     * reader to guess. Served with a .xls filename/content-type so Excel opens it straight from a
     * double-click (it recognizes the XML content regardless of extension). Needs no external
     * library — just XML markup PHP can build with plain strings.
     */
    private function buildSpreadsheetXml(array $headerLabels, array $dataRows): string
    {
        $e    = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $cell = fn(string $value, bool $header = false) =>
            '<Cell' . ($header ? ' ss:StyleID="Header"' : '') . '><Data ss:Type="String">' . str_replace("\n", '&#10;', $e($value)) . '</Data></Cell>';
        $row  = fn(array $values, bool $header = false) =>
            '<Row>' . implode('', array_map(fn($v) => $cell((string) $v, $header), $values)) . '</Row>';

        $rowsXml = $row($headerLabels, true);
        foreach ($dataRows as $r) {
            $rowsXml .= $row($r);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<?mso-application progid="Excel.Sheet"?>' . "\n"
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            . 'xmlns:o="urn:schemas-microsoft-com:office:office" '
            . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
            . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            // Default style wraps text, so a multi-line value (e.g. a date range "od –" / "do") shows on its lines.
            . '<Styles><Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>'
            . '<Style ss:ID="Header"><Font ss:Bold="1"/></Style></Styles>'
            . '<Worksheet ss:Name="Export akci"><Table>' . $rowsXml . '</Table></Worksheet>'
            . '</Workbook>';
    }

    /**
     * Resolves this request's export filters (date range + Typ akce/Kategorie návštěvníků/
     * Organizátor/Stav + columns) and builds the matching rows — shared by the print view and the
     * Excel/CSV download so both apply identical filtering.
     *
     * Each of the four checkbox filters is independent: leaving it entirely unchecked (or never
     * submitting it at all, e.g. a bare bookmarked URL) means "no constraint from this filter" —
     * an action just needs to match at least one checked box in each filter that does have a
     * selection. All filters that do have a selection are ANDed together.
     *
     * @return array{columns: array<string,string>, rows: array<int,array<string,mixed>>, dateFrom: ?string, dateTo: ?string, selectedTypeNames: array<int,string>, allTypesSelected: bool, selectedCategoryNames: array<int,string>, allCategoriesSelected: bool, selectedOrganizerNames: array<int,string>, allOrganizersSelected: bool, selectedStatusNames: array<int,string>, allStatusesSelected: bool}
     */
    private function resolveExport(Request $request): array
    {
        $columns = $this->availableColumns();

        $rawFrom  = $request->get('date_from');
        $rawTo    = $request->get('date_to');
        $dateFrom = ($rawFrom !== null && $rawFrom !== '') ? (string) $rawFrom : null;
        $dateTo   = ($rawTo   !== null && $rawTo   !== '') ? (string) $rawTo   : null;

        $dictionary     = new DictionaryRepository();
        $allTypes       = $dictionary->listActive('action_types');
        $allCategories  = $dictionary->listActive('visitor_categories');
        $allOrganizers  = (new \App\Repositories\UserRepository())->listByRoleCode('organizer');

        $selectedTypeIds       = array_map('intval', (array) $request->get('action_type_id', []));
        $selectedCategoryIds   = array_map('intval', (array) $request->get('visitor_category_id', []));
        $selectedOrganizerIds  = array_map('intval', (array) $request->get('organizer_id', []));
        $selectedStatusIds     = array_map('intval', (array) $request->get('status_id', []));

        $selectedColumns = array_values(array_intersect(
            array_map('strval', (array) $request->get('column', array_keys($columns))),
            array_keys($columns)
        ));
        if (empty($selectedColumns)) {
            $selectedColumns = array_keys($columns);
        }

        $repo    = new ActionRepository();
        $allStatuses = $repo->listStatuses();
        $actions = $repo->listForExport(
            $selectedTypeIds,
            $selectedCategoryIds,
            $selectedOrganizerIds,
            $dateFrom,
            $dateTo !== null ? $dateTo . ' 23:59:59' : null,
            $selectedStatusIds
        );
        $rows = array_map(fn($a) => $this->buildRow($a, $repo, $selectedColumns), $actions);

        $namesOf = fn(array $all, array $selectedIds, string $idKey = 'id', string $nameKey = 'name') => array_values(array_map(
            fn($t) => $t[$nameKey],
            array_filter($all, fn($t) => in_array((int) $t[$idKey], $selectedIds, true))
        ));

        return [
            'columns'                => array_intersect_key($columns, array_flip($selectedColumns)),
            'rows'                   => $rows,
            'dateFrom'               => $dateFrom,
            'dateTo'                 => $dateTo,
            'selectedTypeNames'      => $namesOf($allTypes, $selectedTypeIds),
            'allTypesSelected'       => empty($selectedTypeIds),
            'selectedCategoryNames'  => $namesOf($allCategories, $selectedCategoryIds),
            'allCategoriesSelected'  => empty($selectedCategoryIds),
            'selectedOrganizerNames' => $namesOf($allOrganizers, $selectedOrganizerIds, 'id', 'full_name'),
            'allOrganizersSelected'  => empty($selectedOrganizerIds),
            'selectedStatusNames'    => $namesOf($allStatuses, $selectedStatusIds),
            'allStatusesSelected'    => empty($selectedStatusIds),
        ];
    }

    private function availableColumns(): array
    {
        $columns = self::COLUMNS;
        if (!in_array('action.view_economic', $this->allowedOps(), true)) {
            unset($columns['income_total'], $columns['cost_total']);
        }
        return $columns;
    }

    /** Builds one export row, only computing the (per-action query) values for columns actually selected. */
    private function buildRow(array $action, ActionRepository $repo, array $selectedColumns): array
    {
        $actionId = (int) $action['id'];
        $row = [
            'name'       => $action['name'],
            'event_name' => $action['event_name'],
            'date_range'  => $this->formatDateTimeRange($action['public_start_at'], $action['public_end_at']),
            'staff_range' => $this->formatDateTimeRange($action['staff_start_at'], $action['staff_end_at']),
            'status'          => $action['status_name'],
            'capacity'        => $action['capacity'] !== null ? (int) $action['capacity'] : '—',
            'photograph'      => $action['photograph'] ? 'Ano' : 'Ne',
            'photograph_note' => $action['photograph_note'] !== null && $action['photograph_note'] !== '' ? $action['photograph_note'] : '—',
            'not_main_organizer' => $action['not_main_organizer'] ? 'Ano' : 'Ne',
            'note'            => $action['note'] !== null && $action['note'] !== '' ? $action['note'] : '—',
        ];

        if (in_array('action_types', $selectedColumns, true)) {
            $types = array_map(fn($t) => $t['name'], $repo->getActionTypes($actionId));
            $row['action_types'] = $types ? implode(', ', $types) : '—';
        }
        if (in_array('action_topics', $selectedColumns, true)) {
            $topics = array_map(fn($t) => $t['name'], $repo->getActionTopics($actionId));
            $row['action_topics'] = $topics ? implode(', ', $topics) : '—';
        }
        if (in_array('action_formats', $selectedColumns, true)) {
            $formats = array_map(fn($t) => $t['name'], $repo->getActionFormats($actionId));
            $row['action_formats'] = $formats ? implode(', ', $formats) : '—';
        }
        if (in_array('places', $selectedColumns, true)) {
            $confirmed = array_filter($repo->getPlaceDesires($actionId), fn($d) => $d['confirmed']);
            $names     = array_map(fn($d) => $d['name'], $confirmed);
            $row['places'] = $names ? implode(', ', $names) : '—';
        }
        if (in_array('organizers', $selectedColumns, true)) {
            $names = array_map(fn($o) => $o['full_name'] ?? ('Uživatel #' . $o['user_id']), $repo->getOrganizers($actionId));
            $row['organizers'] = $names ? implode(', ', $names) : '—';
        }
        if (in_array('substitutes', $selectedColumns, true)) {
            $names = array_map(fn($o) => $o['full_name'] ?? ('Uživatel #' . $o['user_id']), $repo->getSubstitutes($actionId));
            $row['substitutes'] = $names ? implode(', ', $names) : '—';
        }
        if (in_array('staff', $selectedColumns, true)) {
            $names = array_map(fn($p) => $p['full_name'] ?? $p['short_name'] ?? ('Osoba #' . $p['person_id']), $repo->getStaff($actionId));
            $row['staff'] = $names ? implode(', ', $names) : '—';
        }
        if (in_array('visitor_categories', $selectedColumns, true)) {
            $names = array_map(fn($c) => $c['name'], $repo->getTargetVisitorCategories($actionId));
            $row['visitor_categories'] = $names ? implode(', ', $names) : '—';
        }
        if (in_array('attendance', $selectedColumns, true)) {
            $row['attendance'] = array_sum(array_column($repo->getAttendance($actionId), 'visitor_count'));
        }
        if (in_array('income_total', $selectedColumns, true) || in_array('cost_total', $selectedColumns, true)) {
            $finance = new FinanceRepository();
            if (in_array('income_total', $selectedColumns, true)) {
                $row['income_total'] = $this->formatTotals($finance->incomeTotals($actionId));
            }
            if (in_array('cost_total', $selectedColumns, true)) {
                $row['cost_total'] = $this->formatTotals($finance->costTotals($actionId));
            }
        }

        return $row;
    }

    private function formatTotals(array $totals): string
    {
        if (empty($totals)) {
            return '—';
        }
        return implode(', ', array_map(
            fn($t) => number_format((float) $t['total'], 2, ',', ' ') . ' ' . ($t['symbol'] ?: $t['code']),
            $totals
        ));
    }

    /**
     * "Přehled místností" specific export: for the given date range, one page per room that has
     * at least one confirmed action in that window, listing each action's name, public start/end,
     * and its organizers' abbreviations. Rooms with no action in range are omitted entirely.
     */
    public function roomsPrint(Request $request, array $params): void
    {
        $this->requireAdmin();

        [$dateFrom, $dateTo] = $this->resolveOptionalRange($request);

        echo View::render('export/print-rooms', [
            'title'    => 'Přehled akcí v místnostech',
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'places'   => $this->roomsData($dateFrom, $dateTo),
        ]);
    }

    /** Same data as roomsPrint, flattened into one sheet — the room becomes the first column instead of a page per room. */
    public function roomsExcel(Request $request, array $params): void
    {
        $this->requireAdmin();

        [$dateFrom, $dateTo] = $this->resolveOptionalRange($request);

        $dataRows = [];
        foreach ($this->roomsData($dateFrom, $dateTo) as $place) {
            $placeName = $place['name'] . ($place['abbreviation'] ? ' (' . $place['abbreviation'] . ')' : '');
            foreach ($place['actions'] as $action) {
                $dataRows[] = [$placeName, $action['name'], $action['date_range'], $action['staff_range'], $action['organizers']];
            }
        }

        $this->sendSpreadsheet(
            'prehled_mistnosti',
            ['Místnost', 'Název akce', 'Datum a čas akce', 'Místnost blokována', 'Organizátoři'],
            $dataRows
        );
    }

    /** @return array{0: ?string, 1: ?string} [dateFrom, dateTo] as given — null means "no limit in that direction". */
    private function resolveOptionalRange(Request $request): array
    {
        $rawFrom  = $request->get('date_from');
        $rawTo    = $request->get('date_to');
        $dateFrom = ($rawFrom !== null && $rawFrom !== '') ? (string) $rawFrom : null;
        $dateTo   = ($rawTo   !== null && $rawTo   !== '') ? (string) $rawTo   : null;
        return [$dateFrom, $dateTo];
    }

    private function sendSpreadsheet(string $filenamePrefix, array $headerLabels, array $dataRows): void
    {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filenamePrefix . '_' . date('Y-m-d') . '.xls"');

        echo $this->buildSpreadsheetXml($headerLabels, $dataRows);
    }

    private function roomsData(?string $dateFrom, ?string $dateTo): array
    {
        $actionRepo = new ActionRepository();
        $places     = (new PlaceRepository())->listWithActionsInRange(
            $dateFrom,
            $dateTo !== null ? $dateTo . ' 23:59:59' : null
        );

        foreach ($places as &$place) {
            foreach ($place['actions'] as &$action) {
                $action['organizers']  = $this->organizerAbbreviations($actionRepo->getOrganizers($action['id']));
                $action['date_range']  = $this->formatDateTimeRange($action['public_start_at'], $action['public_end_at']);
                $action['staff_range'] = $this->formatDateTimeRange($action['staff_start_at'], $action['staff_end_at']);
            }
            unset($action);
        }
        unset($place);

        return $places;
    }

    /**
     * Joins organizers' abbreviations (short_name) for the rooms export — this is a public
     * document, so full names must never appear here. An organizer with no abbreviation set
     * shows as "XXX" rather than falling back to their name.
     */
    private function organizerAbbreviations(array $organizers): string
    {
        if (empty($organizers)) {
            return '—';
        }
        return implode(', ', array_map(fn($o) => $o['short_name'] ?: 'XXX', $organizers));
    }

    /**
     * "Export pro mediální tým" specific export: for the given date range, every action flagged
     * photograph = true, with its public window, confirmed rooms, organizers' full names (not
     * abbreviated, per spec), and the note addressed to the media team.
     */
    public function mediaTeamPrint(Request $request, array $params): void
    {
        $this->requireAdmin();

        [$dateFrom, $dateTo] = $this->resolveOptionalRange($request);

        echo View::render('export/print-media-team', [
            'title'    => 'Přehled akcí pro mediální tým',
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'rows'     => $this->mediaTeamRows($dateFrom, $dateTo),
        ]);
    }

    public function mediaTeamExcel(Request $request, array $params): void
    {
        $this->requireAdmin();

        [$dateFrom, $dateTo] = $this->resolveOptionalRange($request);

        $dataRows = array_map(fn(array $r) => [
            $r['name'], $r['date_range'], $r['staff_range'], $r['places'], $r['organizers'], $r['photograph_note'],
        ], $this->mediaTeamRows($dateFrom, $dateTo));

        $this->sendSpreadsheet(
            'prehled_medialni_tym',
            ['Název akce', 'Datum a čas od–do (pro veřejnost)', 'Blokace místnosti pro personál od–do', 'Místnosti', 'Organizátoři', 'Poznámka pro mediální tým'],
            $dataRows
        );
    }

    private function mediaTeamRows(?string $dateFrom, ?string $dateTo): array
    {
        $repo    = new ActionRepository();
        $actions = $repo->listForMediaTeamExport($dateFrom, $dateTo !== null ? $dateTo . ' 23:59:59' : null);

        return array_map(function (array $a) use ($repo): array {
            $actionId  = (int) $a['id'];
            $confirmed = array_filter($repo->getPlaceDesires($actionId), fn($d) => $d['confirmed']);
            $places    = array_map(fn($d) => $d['name'], $confirmed);
            $organizers = array_map(
                fn($o) => $o['full_name'] ?? ('Uživatel #' . $o['user_id']),
                $repo->getOrganizers($actionId)
            );

            return [
                'name'        => $a['name'],
                'date_range'  => $this->formatDateTimeRange($a['public_start_at'], $a['public_end_at']),
                'staff_range' => $this->formatDateTimeRange($a['staff_start_at'], $a['staff_end_at']),
                'places'          => $places ? implode(', ', $places) : '—',
                'organizers'      => $organizers ? implode(",\n", $organizers) : '—', // one organizer per line
                'photograph_note' => $a['photograph_note'] !== null && $a['photograph_note'] !== '' ? $a['photograph_note'] : '—',
            ];
        }, $actions);
    }

    /**
     * "Export pro údržbu": every action with at least one confirmed room, showing the public and
     * staff room-block windows, organizers and the confirmed rooms (each with its room note and
     * action×room note). The whole-action note is deliberately left out, per spec.
     */
    public function maintenancePrint(Request $request, array $params): void
    {
        $this->requireAdmin();

        [$dateFrom, $dateTo] = $this->resolveOptionalRange($request);

        echo View::render('export/print-maintenance', [
            'title'    => 'Export pro údržbu',
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'rows'     => $this->maintenanceRows($dateFrom, $dateTo),
        ]);
    }

    /** Rooms go into one cell separated by " | ", each followed by its notes (room note; action×room note). */
    public function maintenanceExcel(Request $request, array $params): void
    {
        $this->requireAdmin();

        [$dateFrom, $dateTo] = $this->resolveOptionalRange($request);

        $dataRows = array_map(function (array $r): array {
            $places = array_map(function (array $p): string {
                $notes = array_filter([$p['room_note'] ?? '', $p['action_place_note'] ?? ''], fn($n) => $n !== null && $n !== '');
                return $p['name'] . ($notes ? ' – ' . implode('; ', $notes) : '');
            }, $r['places']);

            return [$r['name'], $r['date_range'], $r['staff_range'], $r['organizers'], $places ? implode(' | ', $places) : '—'];
        }, $this->maintenanceRows($dateFrom, $dateTo));

        $this->sendSpreadsheet(
            'export_udrzba',
            ['Název akce', 'Od-do pro veřejnost', 'Blokace místnosti', 'Organizátoři', 'Místnosti'],
            $dataRows
        );
    }

    private function maintenanceRows(?string $dateFrom, ?string $dateTo): array
    {
        $repo    = new ActionRepository();
        $actions = $repo->listForMaintenanceExport($dateFrom, $dateTo !== null ? $dateTo . ' 23:59:59' : null);

        return array_map(function (array $a) use ($repo): array {
            $actionId   = (int) $a['id'];
            $organizers = array_map(
                fn($o) => $o['full_name'] ?? ('Uživatel #' . $o['user_id']),
                $repo->getOrganizers($actionId)
            );

            return [
                'name'        => $a['name'],
                'date_range'  => $this->formatDateTimeRange($a['public_start_at'], $a['public_end_at']),
                'staff_range' => $this->formatDateTimeRange($a['staff_start_at'], $a['staff_end_at']),
                'organizers'  => $organizers ? implode(",\n", $organizers) : '—', // one organizer per line
                'places'      => $repo->getConfirmedPlacesWithNotes($actionId),
            ];
        }, $actions);
    }

    /**
     * "Plán akcí" specific export: one landscape sheet per calendar week (Mon–Sun) of the range —
     * a range from Wednesday to the following Tuesday gives two sheets, Wed–Sun and Mon–Tue. Every
     * sheet keeps all 7 day columns (days outside the range stay empty) so the days always line up.
     * An action is listed on every day its time overlaps.
     */
    public function weeklyPrint(Request $request, array $params): void
    {
        $this->requireAdmin();

        [$dateFrom, $dateTo] = $this->resolveWeeklyRange($request);

        echo View::render('export/print-weekly', [
            'title' => 'Plán akcí',
            'weeks' => $this->weeklyPlan($dateFrom, $dateTo),
        ]);
    }

    private const DAY_NAMES = ['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'];

    /**
     * A missing (or unreadable) date_from/date_to is taken from the week of the other one: no end →
     * up to that week's Sunday, no start → from that week's Monday, neither → the current week.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveWeeklyRange(Request $request): array
    {
        $parse = function (?string $value): ?\DateTimeImmutable {
            $date = $value !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            return $date ?: null;
        };
        [$rawFrom, $rawTo] = $this->resolveOptionalRange($request);
        $from = $parse($rawFrom);
        $to   = $parse($rawTo);

        $from ??= ($to ?? new \DateTimeImmutable('today'))->modify('monday this week');
        $to   ??= $from->modify('sunday this week');
        if ($to < $from) {
            $to = $from;
        }
        return [$from, $to];
    }

    /**
     * @return array<int, array{title: string, days: array<int, array{name: string, date: string, inRange: bool, actions: array}>}>
     */
    private function weeklyPlan(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $repo    = new ActionRepository();
        $actions = array_map(function (array $a) use ($repo): array {
            $places = array_filter($repo->getPlaceDesires((int) $a['id']), fn($d) => $d['confirmed']);
            return $a + [
                'start'      => $a['public_start_at'] ?? $a['staff_start_at'],
                'end'        => $a['public_end_at'] ?? $a['public_start_at'] ?? $a['staff_end_at'] ?? $a['staff_start_at'],
                'places'     => implode(', ', array_map(fn($d) => $d['name'], $places)),
                'organizers' => $this->organizerAbbreviations($repo->getOrganizers((int) $a['id'])),
            ];
        }, $repo->listForWeeklyPlan($from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 23:59:59')));

        $weeks = [];
        for ($monday = $from->modify('monday this week'); $monday <= $to; $monday = $monday->modify('+1 week')) {
            $sunday = $monday->modify('+6 days');
            $days   = [];
            foreach (self::DAY_NAMES as $i => $dayName) {
                $day     = $monday->modify("+{$i} days");
                $inRange = $day >= $from && $day <= $to;
                $dayFrom = $day->format('Y-m-d 00:00:00');
                $dayTo   = $day->format('Y-m-d 23:59:59');

                $dayActions = !$inRange ? [] : array_values(array_map(fn(array $a) => [
                    'name'        => $a['name'],
                    'public_time' => $this->planTimeSpan($a['public_start_at'], $a['public_end_at'], $day),
                    'staff_time'  => $this->planTimeSpan($a['staff_start_at'], $a['staff_end_at'], $day),
                    'places'      => $a['places'],
                    'organizers'  => $a['organizers'],
                ], array_filter($actions, fn(array $a) => $a['start'] !== null && $a['start'] <= $dayTo && $a['end'] >= $dayFrom)));

                $days[] = ['name' => $dayName, 'date' => $day->format('j. n.'), 'inRange' => $inRange, 'actions' => $dayActions];
            }

            $weeks[] = [
                'title' => 'Plán akcí pro ' . $this->planRangeLabel(max($monday, $from), min($sunday, $to)),
                'days'  => $days,
            ];
        }
        return $weeks;
    }

    /** "4. 11. – 8. 11. 2026"; with the year on both sides when the range crosses New Year. */
    private function planRangeLabel(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return $from->format('Y') === $to->format('Y')
            ? $from->format('j. n.') . ' – ' . $to->format('j. n. Y')
            : $from->format('j. n. Y') . ' – ' . $to->format('j. n. Y');
    }

    /** "9:30–11:00" on the given day; a side falling on another day also gets its date ("9:30–5. 11. 16:00"). Null when there's no start. */
    private function planTimeSpan(?string $start, ?string $end, \DateTimeImmutable $day): ?string
    {
        if ($start === null) {
            return null;
        }
        $format = fn(string $ts) => date(date('Y-m-d', strtotime($ts)) === $day->format('Y-m-d') ? 'G:i' : 'j. n. G:i', strtotime($ts));
        return $format($start) . ($end !== null ? '–' . $format($end) : '');
    }

    /**
     * Formats a nullable start/end timestamp pair as "j. n. Y H:i –" and "j. n. Y H:i" on two lines (broken
     * right after the separator — <br> in print views via nl2br, a wrapped line in Excel), '—' for a missing side.
     */
    private function formatDateTimeRange(?string $start, ?string $end): string
    {
        return ($start ? date('j. n. Y H:i', strtotime($start)) : '—')
            . " –\n"
            . ($end ? date('j. n. Y H:i', strtotime($end)) : '—');
    }

    public function statisticsPrint(Request $request, array $params): void
    {
        $this->requireAdmin();

        [$dateFrom, $dateTo] = $this->resolveStatisticsRange($request);

        echo View::render('export/print-statistics', [
            'title'    => 'Statistika',
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'rows'     => $this->statisticsRows($dateFrom, $dateTo),
        ]);
    }

    public function statisticsExcel(Request $request, array $params): void
    {
        $this->requireAdmin();

        [$dateFrom, $dateTo] = $this->resolveStatisticsRange($request);
        $rows = $this->statisticsRows($dateFrom, $dateTo);

        $headerLabels = ['Kód', 'Název', 'Hodnota'];
        $dataRows = array_map(fn(array $r) => [
            $r['code'] ?? '',
            $r['label'],
            $r['value'] ?? '',
        ], $rows);

        $this->sendSpreadsheet('statistika', $headerLabels, $dataRows);
    }

    /** @return array{0: string, 1: string} [dateFrom, dateTo], both 'Y-m-d' — defaults to the current calendar year when not given. */
    private function resolveStatisticsRange(Request $request): array
    {
        $rawFrom = $request->get('date_from');
        $rawTo   = $request->get('date_to');
        $dateFrom = ($rawFrom !== null && $rawFrom !== '') ? (string) $rawFrom : date('Y') . '-01-01';
        $dateTo   = ($rawTo   !== null && $rawTo   !== '') ? (string) $rawTo   : date('Y') . '-12-31';
        return [$dateFrom, $dateTo];
    }

    /**
     * Builds the "Statistika" report rows for the given date range — mirrors the official
     * NIPOS/MK ČR library statistics codes (01A.1, 01B, 01C…) as specified by the librarian.
     * Type names 'Kulturní'/'Sociální'/'Vzdělávací' are treated as mutually-exclusive buckets for
     * this report specifically (Sociální = "komunitní/společenská"), even though the app itself
     * allows an action to carry several Types at once — an action with several matching Types is
     * counted into each matching row, same as the underlying paper report would.
     *
     * @return array<int, array{code: ?string, label: string, value: ?string}>
     */
    private function statisticsRows(string $dateFrom, string $dateTo): array
    {
        $stats = new StatisticsRepository();

        // Each bucket lists every wording the library has used for the same concept over time
        // (current active name plus older/deactivated synonyms) — a differently-worded or
        // deactivated-then-renamed Typ akce still counts, per the librarian: "Kultura" is the
        // same thing as "Kulturní", "Sociální" is the same thing as "Komunitní", etc.
        $KULTURNI_KOMUNITNI = ['Kulturní', 'Kultura', 'Sociální', 'Komunitní'];
        $VZDELAVACI         = ['Vzdělávací', 'Vzdělávání', 'Vzdělávaci'];

        // A null result from StatisticsRepository means "some named dictionary entry this row
        // depends on wasn't found" (a Typ akce, Téma, Provedení, Kategorie, Náklady — za co) — the
        // value is then left empty rather than shown as a misleading zero (a genuine zero still
        // comes back as 0).
        $count = fn(?int $n): ?string => $n !== null ? (string) $n : null;
        $hours = fn(?int $seconds): ?string => $seconds !== null ? $this->formatDuration($seconds) : null;
        $money = fn(?float $amount): ?string => $amount !== null ? $this->formatMoney($amount) : null;

        $rows = [];

        $rows[] = $this->statRow('01A.1', 'Kulturní, komunitní a volnočasové akce pro veřejnost (besedy, výstavy, aj.)',
            $count($stats->countActionsByTypes($KULTURNI_KOMUNITNI, $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01A.1.1', '-- z toho online (virtuální) kulturní, komunitní a volnočasové akce pro veřejnost',
            $count($stats->countActionsByTypesAndFormat($KULTURNI_KOMUNITNI, 'Online', $dateFrom, $dateTo)));

        // "Výstava" is tracked as its own Typ akce (not a Téma) per the librarian's setup — a
        // direct type-count, same shape as the 01A.2 Vzdělávací row below, not an AndTopic lookup.
        $rows[] = $this->statRow('01A.1.2', '-- z toho počet výstav',
            $count($stats->countActionsByTypes(['Výstava'], $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01A.2', 'Vzdělávací akce pro veřejnost (semináře, kurzy, aj.)',
            $count($stats->countActionsByTypes($VZDELAVACI, $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01A.2.1', '-- počet hodin vzdělávacích akcí pro veřejnost',
            $hours($stats->sumPublicSecondsByTypes($VZDELAVACI, $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01A.2.2', '-- počet online (virtuálních) vzdělávacích akcí pro veřejnost',
            $count($stats->countActionsByTypesAndFormat($VZDELAVACI, 'Online', $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01A.2.3', '-- počet hodin online (virtuálních) vzdělávacích akcí pro veřejnost',
            $hours($stats->sumPublicSecondsByTypesAndFormat($VZDELAVACI, 'Online', $dateFrom, $dateTo)));

        // The dictionary's Téma is spelled out as "Informační technologie", not the "ICT"
        // abbreviation — same concept, different wording, same principle as Kultura/Kulturní above.
        $ictTopicName = 'Informační technologie';
        $rows[] = $this->statRow('01A.2.4', '-- vzdělávací akce v oblasti ICT (inform. a komunikačních technologií)',
            $count($stats->countActionsByTypesAndTopic($VZDELAVACI, $ictTopicName, $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01A.2.5', '-- počet hodin vzdělávacích akcí v oblasti ICT (inform. a komunikačních technologií)',
            $hours($stats->sumPublicSecondsByTypesAndTopic($VZDELAVACI, $ictTopicName, $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01A.3', 'Ostatní akce, kde knihovna není hlavním pořadatelem',
            (string) $stats->countActionsByNotMainOrganizer($dateFrom, $dateTo));

        $rows[] = $this->statRow(null, '01.B – Návštěvnost akcí knihovny', null);

        $rows[] = $this->statRow('01.B', 'Návštěvníci všech akcí knihovny',
            (string) $stats->sumAttendanceByTypes([], $dateFrom, $dateTo));

        $rows[] = $this->statRow('01.B.1', '-- počet návštěvníků kulturních akcí (celkem)',
            $count($stats->sumAttendanceByTypes($KULTURNI_KOMUNITNI, $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01.B.1.1', '-- počet platících návštěvníků kulturních akcí (celkem)',
            $count($stats->sumAttendanceByTypes($KULTURNI_KOMUNITNI, $dateFrom, $dateTo, 'Platící')));

        $rows[] = $this->statRow('01.B.2', '-- počet návštěvníků vzdělávacích akcí',
            $count($stats->sumAttendanceByTypes($VZDELAVACI, $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01.B.2.1', '-- počet platících návštěvníků vzdělávacích akcí (celkem)',
            $count($stats->sumAttendanceByTypes($VZDELAVACI, $dateFrom, $dateTo, 'Platící')));

        // "Struktura návštěvníků vzdělávacích akcí" — per the librarian, this counts attendance
        // only for actions carrying the Vzdělávací type (they may carry other Types too, but
        // Vzdělávací must be among them), broken down by visitor Kategorie.
        $rows[] = $this->statRow('01.B.2.2a', '-- struktura návštěvníků vzdělávacích akcí: počet návštěvníků z řad žáků 1. stupně ZŠ',
            $count($stats->sumAttendanceByTypesAndVisitorCategory($VZDELAVACI, 'Žáci 1. st. ZŠ', $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01.B.2.2b', '-- struktura návštěvníků vzdělávacích akcí: počet návštěvníků z řad žáků 2. stupně ZŠ (nebo ekvivalentních ročníků SŠ)',
            $count($stats->sumAttendanceByTypesAndVisitorCategory($VZDELAVACI, 'Žáci 2. st. ZŠ', $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01.B.2.2c', '-- struktura návštěvníků vzdělávacích akcí: počet návštěvníků z řad studentů SŠ',
            $count($stats->sumAttendanceByTypesAndVisitorCategory($VZDELAVACI, 'Žáci SŠ', $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01.B.2.2d', '-- struktura návštěvníků vzdělávacích akcí: počet návštěvníků z řad žáků mateřských škol',
            $count($stats->sumAttendanceByTypesAndVisitorCategory($VZDELAVACI, 'Žáci MŠ', $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01.B.3', '-- počet návštěvníků ostatních akcí, kde knihovna není hlavním pořadatelem',
            (string) $stats->sumAttendanceByNotMainOrganizer($dateFrom, $dateTo));

        $rows[] = $this->statRow(null, '01.C – Ekonomika akcí', null);

        $rows[] = $this->statRow('01.C.1', '-- celkové příjmy ze vstupného na akce pořádané knihovnou',
            $this->formatMoney((float) $stats->sumIncomeVstupneByTypes([], $dateFrom, $dateTo)));

        $KULTURA_ONLY   = ['Kulturní', 'Kultura'];
        $SOCIALNI_ONLY  = ['Sociální', 'Komunitní'];

        $rows[] = $this->statRow('01.C.1.1', '-- příjmy ze vstupného - kulturní akce',
            $money($stats->sumIncomeVstupneByTypes($KULTURA_ONLY, $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01.C.1.2', '-- příjmy ze vstupného - vzdělávací akce',
            $money($stats->sumIncomeVstupneByTypes($VZDELAVACI, $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01.C.1.3', '-- příjmy ze vstupného - komunitní / společenské akce',
            $money($stats->sumIncomeVstupneByTypes($SOCIALNI_ONLY, $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01.C.2', '-- výdaje na zajištění programu knihovny',
            $money($stats->sumCostByObjectName('Honorář', $dateFrom, $dateTo)));

        $rows[] = $this->statRow('01.C.3', '-- výdaje na propagaci programu knihovny',
            $money($stats->sumCostByObjectName('Propagace', $dateFrom, $dateTo)));

        return $rows;
    }

    /** @return array{code: ?string, label: string, value: ?string} */
    private function statRow(?string $code, string $label, ?string $value): array
    {
        return ['code' => $code, 'label' => $label, 'value' => $value];
    }

    /** Formats a total-seconds duration as (possibly >99) hours:MM:SS, matching the source report's own format (e.g. "630:15:00"). */
    private function formatDuration(int $totalSeconds): string
    {
        $h = intdiv($totalSeconds, 3600);
        $m = intdiv($totalSeconds % 3600, 60);
        $s = $totalSeconds % 60;
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
