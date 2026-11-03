<?php
$monthNames = ['', 'Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen',
               'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'];
$prevMonth = $month === 1 ? ['year' => $year - 1, 'month' => 12] : ['year' => $year, 'month' => $month - 1];
$nextMonth = $month === 12 ? ['year' => $year + 1, 'month' => 1] : ['year' => $year, 'month' => $month + 1];
$statusQs  = implode('', array_map(fn($c) => '&status[]=' . urlencode($c), $statusCodes)) . '&filtered=1' . ($onlyMine ? '&only_mine=1' : '')
           . implode('', array_map(fn($id) => '&place_id[]=' . urlencode((string) $id), $placeIds));
$onlyMineWarning = 'POZOR - nevidíte všechny akce, ale pouze ty své';
$placeFilterWarning = 'POZOR - nevidíte všechny akce, ale pouze ty ve zvolených místnostech';
?>
<script>rememberQueryOnLoad('bad-event:filter:actions', 'filtered');</script>

<div class="page-header">
    <h1><?= \App\Core\View::e($title) ?></h1>
    <div class="page-actions">
        <?= permLink(in_array('action.create', $allowedOps, true), url('/actions/create'), '+ Nová akce', 'btn btn-primary', 'Nemáte oprávnění vytvořit akci.') ?>
    </div>
</div>

<div class="card">
    <form method="GET" action="<?= url('/actions') ?>" class="filter-form">
        <input type="hidden" name="filtered" value="1">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <div class="form-section">
            <div class="checkbox-grid">
                <?php foreach ($statuses as $s): ?>
                    <?php $isTerminal = in_array($s['code'], $terminal, true); ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="status[]" value="<?= \App\Core\View::e($s['code']) ?>"
                               <?= in_array($s['code'], $statusCodes, true) ? 'checked' : '' ?>
                               <?= ($isTerminal && !$canViewArchive) ? 'disabled title="Nemáte oprávnění zobrazit archiv."' : '' ?>>
                        <?= \App\Core\View::e($s['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-section">
            <div class="checkbox-grid">
                <label class="checkbox-label">
                    <input type="checkbox" name="only_mine" value="1" <?= $onlyMine ? 'checked' : '' ?>>
                    Zobrazit pouze moje akce
                </label>
            </div>
        </div>
        <div class="form-section">
            <div class="checkbox-grid">
                <?php foreach ($places as $p): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="place_id[]" value="<?= (int) $p['id'] ?>"
                               <?= in_array((int) $p['id'], $placeIds, true) ? 'checked' : '' ?>>
                        <?= \App\Core\View::e($p['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Filtrovat</button>
    </form>
</div>

<?php if ($onlyMine): ?>
    <p class="only-mine-warning"><?= \App\Core\View::e($onlyMineWarning) ?></p>
<?php endif; ?>
<?php if (!empty($placeIds)): ?>
    <p class="only-mine-warning"><?= \App\Core\View::e($placeFilterWarning) ?></p>
<?php endif; ?>

<?php if (!empty($dayNote)): ?>
<p class="day-note-banner">Dnes <?= \App\Core\View::e(date('j. n. Y')) ?><br><?= nl2br(\App\Core\View::e($dayNote)) ?></p>
<?php endif; ?>

<div class="calendar-nav">
    <a href="<?= url('/actions?year=' . $prevMonth['year'] . '&month=' . $prevMonth['month'] . $statusQs) ?>" class="btn btn-ghost">← <?= $monthNames[$prevMonth['month']] ?></a>
    <h2 class="calendar-month"><?= $monthNames[$month] ?> <?= $year ?></h2>
    <a href="<?= url('/actions?year=' . $nextMonth['year'] . '&month=' . $nextMonth['month'] . $statusQs) ?>" class="btn btn-ghost"><?= $monthNames[$nextMonth['month']] ?> →</a>
</div>

<div class="card">
    <table class="calendar-table">
        <thead>
            <tr>
                <th>Po</th><th>Út</th><th>St</th><th>Čt</th><th>Pá</th>
                <th class="weekend">So</th><th class="weekend">Ne</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grid as $week): ?>
            <tr>
                <?php foreach ($week as $i => $cell): ?>
                <td class="cal-cell<?= $cell['otherMonth'] ? ' other-month' : '' ?><?= $cell['isToday'] ? ' today' : '' ?><?= $i >= 5 ? ' weekend' : '' ?>">
                    <span class="cal-day"><?= $cell['day'] ?></span>
                    <?php foreach ($cell['actions'] as $a): ?>
                    <a href="<?= url('/actions/' . (int) $a['id']) ?>" class="cal-event status-<?= \App\Core\View::e($a['status_code']) ?>">
                        <?= \App\Core\View::e($a['name']) ?>
                        <?php if (!empty($a['places'])): ?>
                            <small><?= \App\Core\View::e($a['places']) ?></small>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="calendar-legend">
    <span class="legend-item"><span class="status-badge status-draft">Rozjednaná</span></span>
    <span class="legend-item"><span class="status-badge status-not_agreed">Nedojednaná</span></span>
    <span class="legend-item"><span class="status-badge status-confirmed">Potvrzená</span></span>
    <span class="legend-item"><span class="status-badge status-completed">Proběhlá</span></span>
    <span class="legend-item"><span class="status-badge status-cancelled">Zrušená</span></span>
    <span class="legend-item"><span class="status-badge status-closed">Uzavřená</span></span>
</div>

<div class="card">
    <form method="GET" action="<?= url('/actions') ?>" class="filter-form">
        <input type="hidden" name="filtered" value="1">
        <?php foreach ($statusCodes as $__sc): ?>
        <input type="hidden" name="status[]" value="<?= \App\Core\View::e($__sc) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <?php if ($onlyMine): ?>
        <input type="hidden" name="only_mine" value="1">
        <?php endif; ?>
        <?php foreach ($placeIds as $__pid): ?>
        <input type="hidden" name="place_id[]" value="<?= (int) $__pid ?>">
        <?php endforeach; ?>
        <div class="form-row">
            <div class="form-group">
                <label for="date_from">Datum od</label>
                <input type="date" name="date_from" id="date_from" value="<?= \App\Core\View::e((string) $dateFrom) ?>">
            </div>
            <div class="form-group">
                <label for="date_to">Datum do</label>
                <input type="date" name="date_to" id="date_to" value="<?= \App\Core\View::e((string) $dateTo) ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Filtrovat podle času</button>
    </form>
</div>

<?php if ($onlyMine): ?>
    <p class="only-mine-warning"><?= \App\Core\View::e($onlyMineWarning) ?></p>
<?php endif; ?>
<?php if (!empty($placeIds)): ?>
    <p class="only-mine-warning"><?= \App\Core\View::e($placeFilterWarning) ?></p>
<?php endif; ?>

<?php if (empty($actions)): ?>
    <p class="empty-state">Žádné akce k zobrazení.</p>
<?php else: ?>
<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th data-sort="text">Název</th>
                <th data-sort="text">Událost</th>
                <th data-sort="date">Datum</th>
                <th>Stav</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($actions as $a): ?>
            <tr>
                <td><?= \App\Core\View::e($a['name']) ?></td>
                <td><?= \App\Core\View::e($a['event_name']) ?></td>
                <td data-sort-value="<?= \App\Core\View::e((string) ($a['public_start_at'] ?? '')) ?>">
                    <?= $a['public_start_at'] ? \App\Core\View::e(date('j. n. Y H:i', strtotime($a['public_start_at']))) : '—' ?>
                    –
                    <?= $a['public_end_at'] ? \App\Core\View::e(date('j. n. Y H:i', strtotime($a['public_end_at']))) : '—' ?>
                </td>
                <td><span class="status-badge status-<?= \App\Core\View::e($a['status_code']) ?>"><?= \App\Core\View::e($a['status_name']) ?></span></td>
                <td><a href="<?= url('/actions/' . (int) $a['id']) ?>" class="btn btn-sm">Detail</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
