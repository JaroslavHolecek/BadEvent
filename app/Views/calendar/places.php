<?php
$monthNames = ['', 'Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen',
               'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'];
$prevMonth = $month === 1 ? ['year' => $year - 1, 'month' => 12] : ['year' => $year, 'month' => $month - 1];
$nextMonth = $month === 12 ? ['year' => $year + 1, 'month' => 1] : ['year' => $year, 'month' => $month + 1];
$daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

// Groups each place's actions by every day they touch (also feeds the day-detail JS panel
// below), and doubles as the "occupied" lookup for the month grid's compact per-cell pills.
// An action spanning midnight (staff/public end on a later date than it started) is bucketed
// into each date in that range, not just its start date — so it shows up on every day it
// actually occupies the room.
// Only the days of the month actually rendered below are ever looked up from this map, so the
// loop is clamped to that range — this also protects against a corrupt/implausible date (e.g. a
// mistyped year) turning a single action into a centuries-long loop that times out the request.
$__monthFirst = sprintf('%04d-%02d-01', $year, $month);
$__monthLast  = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

$__byPlaceAndDay = [];
foreach ($places as $place) {
    $days = [];
    foreach ($place['actions'] as $a) {
        $starts = array_filter([$a['staff_start_at'], $a['public_start_at']]);
        if (empty($starts)) {
            continue;
        }
        $rangeStart = min($starts);
        $ends       = array_filter([$a['staff_end_at'], $a['public_end_at']]);
        $rangeEnd   = empty($ends) ? $rangeStart : max($ends);

        $cursor = max(substr($rangeStart, 0, 10), $__monthFirst);
        $last   = min(substr($rangeEnd, 0, 10), $__monthLast);
        while ($cursor <= $last) {
            $days[$cursor][] = $a;
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }
    }
    $__byPlaceAndDay[(int) $place['id']] = $days;
}
?>
<script>rememberQueryOnLoad('bad-event:filter:calendar-places', 'year');</script>

<div class="page-header">
    <h1>Kalendář místností</h1>
    <div class="page-actions">
        <div class="duty-today">
            <span>Dnes kóduje: <strong><?= $todayDuty && $todayDuty['primary_name'] ? \App\Core\View::e($todayDuty['primary_name']) : '--- NIKDO ---' ?></strong></span>
            <span>Náhradník: <strong><?= $todayDuty && $todayDuty['substitute_name'] ? \App\Core\View::e($todayDuty['substitute_name']) : '--- NIKDO ---' ?></strong></span>
        </div>
        <?= permLink($canManageDuty, url('/admin/building-code-duty'), 'Kódování budovy a poznámka ke dni', 'btn btn-ghost', 'Nemáte oprávnění spravovat kódování budovy.') ?>
    </div>
</div>

<?php if (!empty($dayNote)): ?>
<p class="day-note-banner">Dnes <?= \App\Core\View::e(date('j. n. Y')) ?><br><?= nl2br(\App\Core\View::e($dayNote)) ?></p>
<?php endif; ?>

<div class="calendar-nav">
    <a href="<?= url('/calendar/places?year=' . $prevMonth['year'] . '&month=' . $prevMonth['month']) ?>" class="btn btn-ghost">← <?= $monthNames[$prevMonth['month']] ?></a>
    <h2 class="calendar-month"><?= $monthNames[$month] ?> <?= $year ?></h2>
    <a href="<?= url('/calendar/places?year=' . $nextMonth['year'] . '&month=' . $nextMonth['month']) ?>" class="btn btn-ghost"><?= $monthNames[$nextMonth['month']] ?> →</a>
</div>

<?php if (empty($places)): ?>
    <p class="empty-state">Žádné místnosti nejsou registrovány.</p>
<?php else: ?>
<div class="card places-calendar-wrap">
    <div class="places-calendar-scroll">
        <table class="places-calendar-table">
            <thead>
                <tr>
                    <th class="place-name-col">Místnost</th>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++):
                        $date    = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $dayOfWeek = (int) date('N', strtotime($date));
                        $isToday = $date === date('Y-m-d');
                    ?>
                    <th class="day-col<?= $dayOfWeek >= 6 ? ' weekend' : '' ?><?= $isToday ? ' today' : '' ?>">
                        <?= $d ?>
                    </th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($places as $place): ?>
                <?php $placeId = (int) $place['id']; $occupied = $__byPlaceAndDay[$placeId]; ?>
                <tr>
                    <td class="place-name-col">
                        <strong><?= \App\Core\View::e($place['name']) ?></strong>
                        <?php if ($place['abbreviation']): ?>
                            <br><small><?= \App\Core\View::e($place['abbreviation']) ?></small>
                        <?php endif; ?>
                    </td>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++):
                        $date     = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $dayOfWeek = (int) date('N', strtotime($date));
                        $isToday  = $date === date('Y-m-d');
                        $dayActions = $occupied[$date] ?? [];
                    ?>
                    <td class="day-col<?= $dayOfWeek >= 6 ? ' weekend' : '' ?><?= $isToday ? ' today' : '' ?><?= !empty($dayActions) ? ' occupied' : '' ?>">
                        <button type="button" class="place-day-btn" data-place-id="<?= $placeId ?>" data-date="<?= $date ?>"
                                data-place-name="<?= \App\Core\View::e($place['name']) ?>"
                                data-place-abbr="<?= \App\Core\View::e((string) $place['abbreviation']) ?>"
                                title="Zobrazit detail dne">▾</button>
                        <?php foreach ($dayActions as $a): ?>
                            <a href="<?= url('/actions/' . (int) $a['id']) ?>"
                               class="place-event status-<?= \App\Core\View::e($a['status_code']) ?><?= $a['is_queued'] ? ' place-event-queued' : '' ?>"
                               title="<?= \App\Core\View::e($a['name']) ?><?= $a['is_queued'] ? ' (ve frontě, pozice ' . (int) $a['queue_position'] . ')' : '' ?>">
                                <?= \App\Core\View::e(mb_substr($a['name'], 0, 8)) ?>…<?= $a['is_queued'] ? ' #' . (int) $a['queue_position'] : '' ?>
                            </a>
                        <?php endforeach; ?>
                    </td>
                    <?php endfor; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="calendar-legend">
    <span class="legend-item"><span class="status-badge status-draft">Rozjednaná</span></span>
    <span class="legend-item"><span class="status-badge status-confirmed">Potvrzená</span></span>
    <span class="legend-item"><span class="status-badge status-completed">Proběhlá</span></span>
    <span class="legend-item"><span class="place-event place-event-queued">Ve frontě</span></span>
</div>

<div id="place-day-detail" class="card place-day-detail" data-actions-base="<?= url('/actions') ?>" hidden></div>
<script type="application/json" id="places-calendar-data"><?= json_encode($__byPlaceAndDay, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
