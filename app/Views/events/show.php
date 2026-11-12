<div class="page-header">
    <div>
        <a href="<?= url('/events') ?>" class="breadcrumb">← Události</a>
        <h1><?= \App\Core\View::e($event['name']) ?></h1>
    </div>
    <div class="page-actions">
        <?= permLink(in_array('action.update_basic', $allowedOps, true), url('/events/' . (int) $event['id'] . '/edit'), 'Upravit', 'btn btn-ghost', 'Nemáte oprávnění upravit událost.') ?>
        <?= permLink(in_array('action.create', $allowedOps, true), url('/actions/create?event_id=' . (int) $event['id']), '+ Přidat akci', 'btn btn-primary', 'Nemáte oprávnění vytvořit akci.') ?>
    </div>
</div>

<?php if ($event['note']): ?>
<div class="card">
    <p><?= nl2br(\App\Core\View::e($event['note'])) ?></p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Akce v události</h2></div>
    <?php if (empty($actions)): ?>
        <p class="empty-state">Žádné akce.</p>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>Název</th>
                <th>Datum</th>
                <th>Stav</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($actions as $a): ?>
            <tr>
                <td><?= \App\Core\View::e($a['name']) ?></td>
                <td><?= $a['public_start_at'] ? \App\Core\View::e(date('j. n. Y H:i', strtotime($a['public_start_at']))) : '—' ?></td>
                <td><span class="status-badge status-<?= \App\Core\View::e($a['status_code']) ?>"><?= \App\Core\View::e($a['status_name']) ?></span></td>
                <td><a href="<?= url('/actions/' . (int) $a['id']) ?>" class="btn btn-sm">Detail</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
// Main values leave out not_agreed + cancelled actions (same rule as the Statistika export); the second
// column ("Se zahrnutím všech akcí") counts every action of the event.
$statsExcluded = ['not_agreed', 'cancelled'];
$countedActions = count(array_filter($actions, static fn(array $a): bool => !in_array($a['status_code'], $statsExcluded, true)));
$money = static fn(array $t, string $key): string =>
    \App\Core\View::e(number_format((float) $t[$key], 2, ',', ' ') . ' ' . ($t['symbol'] ?: $t['code']));
$allColumn = 'Se zahrnutím všech akcí';
?>
<div class="card">
    <div class="card-header"><h2>Statistiky události</h2></div>
    <p class="stats-note">Hlavní hodnoty jsou bez zrušených a nedojednaných akcí; sloupec „<?= \App\Core\View::e($allColumn) ?>“ počítá všechny akce události.</p>

    <table class="table">
        <thead><tr><th></th><th>Počet</th><th><?= \App\Core\View::e($allColumn) ?></th></tr></thead>
        <tbody>
            <tr>
                <td>Počet akcí</td>
                <td><?= $countedActions ?></td>
                <td><?= count($actions) ?></td>
            </tr>
        </tbody>
    </table>

    <?php if (in_array('action.view_economic', $allowedOps, true)): ?>
    <?php foreach ([
        ['Příjmy podle typu', 'Žádné příjmy.', $incomeTotalsByType, $incomeGrandTotals],
        ['Výdaje podle typu', 'Žádné výdaje.', $costTotalsByType, $costGrandTotals],
    ] as [$heading, $emptyText, $byType, $grandTotals]): ?>
    <h3><?= \App\Core\View::e($heading) ?></h3>
    <?php if (empty($byType)): ?>
        <p class="empty-state"><?= \App\Core\View::e($emptyText) ?></p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Typ</th><th>Měna</th><th>Celkem</th><th><?= \App\Core\View::e($allColumn) ?></th></tr></thead>
        <tbody>
            <?php foreach ($byType as $t): ?>
            <tr>
                <td><?= \App\Core\View::e($t['type_name']) ?></td>
                <td><?= \App\Core\View::e($t['code']) ?></td>
                <td><?= $money($t, 'total') ?></td>
                <td><?= $money($t, 'total_all') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if (!empty($grandTotals)): ?>
        <tfoot>
            <?php foreach ($grandTotals as $t): ?>
            <tr>
                <td colspan="2"><strong>Celkem <?= \App\Core\View::e($t['code']) ?></strong></td>
                <td><strong><?= $money($t, 'total') ?></strong></td>
                <td><strong><?= $money($t, 'total_all') ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tfoot>
        <?php endif; ?>
    </table>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php else: ?>
        <?= permDenied('Nemáte oprávnění zobrazit ekonomické údaje.') ?>
    <?php endif; ?>

    <h3>Statistiky návštěvnosti</h3>
    <?php if (empty($attendanceTotals)): ?>
        <p class="empty-state">Žádná zaznamenaná docházka.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Kategorie návštěvníků</th><th>Počet</th><th><?= \App\Core\View::e($allColumn) ?></th></tr></thead>
        <tbody>
            <?php foreach ($attendanceTotals as $t): ?>
            <tr>
                <td><?= \App\Core\View::e($t['name']) ?></td>
                <td><?= (int) $t['total'] ?></td>
                <td><?= (int) $t['total_all'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Celkem</strong></td>
                <td><strong><?= array_sum(array_map('intval', array_column($attendanceTotals, 'total'))) ?></strong></td>
                <td><strong><?= array_sum(array_map('intval', array_column($attendanceTotals, 'total_all'))) ?></strong></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>
