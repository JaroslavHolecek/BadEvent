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

<div class="card">
    <div class="card-header"><h2>Statistiky události</h2></div>

    <dl class="detail-grid">
        <dt>Počet akcí</dt>
        <dd><?= count($actions) ?></dd>
    </dl>

    <?php if (in_array('action.view_economic', $allowedOps, true)): ?>
    <h3>Příjmy podle typu</h3>
    <?php if (empty($incomeTotalsByType)): ?>
        <p class="empty-state">Žádné příjmy.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Typ</th><th>Měna</th><th>Celkem</th></tr></thead>
        <tbody>
            <?php foreach ($incomeTotalsByType as $t): ?>
            <tr>
                <td><?= \App\Core\View::e($t['type_name']) ?></td>
                <td><?= \App\Core\View::e($t['code']) ?></td>
                <td><?= \App\Core\View::e(number_format((float) $t['total'], 2, ',', ' ')) ?> <?= \App\Core\View::e($t['symbol'] ?: $t['code']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if (!empty($incomeGrandTotals)): ?>
        <tfoot>
            <?php foreach ($incomeGrandTotals as $t): ?>
            <tr>
                <td colspan="2"><strong>Celkem <?= \App\Core\View::e($t['code']) ?></strong></td>
                <td><strong><?= \App\Core\View::e(number_format((float) $t['total'], 2, ',', ' ')) ?> <?= \App\Core\View::e($t['symbol'] ?: $t['code']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tfoot>
        <?php endif; ?>
    </table>
    <?php endif; ?>

    <h3>Výdaje podle typu</h3>
    <?php if (empty($costTotalsByType)): ?>
        <p class="empty-state">Žádné výdaje.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Typ</th><th>Měna</th><th>Celkem</th></tr></thead>
        <tbody>
            <?php foreach ($costTotalsByType as $t): ?>
            <tr>
                <td><?= \App\Core\View::e($t['type_name']) ?></td>
                <td><?= \App\Core\View::e($t['code']) ?></td>
                <td><?= \App\Core\View::e(number_format((float) $t['total'], 2, ',', ' ')) ?> <?= \App\Core\View::e($t['symbol'] ?: $t['code']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if (!empty($costGrandTotals)): ?>
        <tfoot>
            <?php foreach ($costGrandTotals as $t): ?>
            <tr>
                <td colspan="2"><strong>Celkem <?= \App\Core\View::e($t['code']) ?></strong></td>
                <td><strong><?= \App\Core\View::e(number_format((float) $t['total'], 2, ',', ' ')) ?> <?= \App\Core\View::e($t['symbol'] ?: $t['code']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tfoot>
        <?php endif; ?>
    </table>
    <?php endif; ?>
    <?php else: ?>
        <?= permDenied('Nemáte oprávnění zobrazit ekonomické údaje.') ?>
    <?php endif; ?>

    <h3>Statistiky návštěvnosti</h3>
    <?php if (empty($attendanceTotals)): ?>
        <p class="empty-state">Žádná zaznamenaná docházka.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Kategorie návštěvníků</th><th>Počet</th></tr></thead>
        <tbody>
            <?php foreach ($attendanceTotals as $t): ?>
            <tr>
                <td><?= \App\Core\View::e($t['name']) ?></td>
                <td><?= (int) $t['total'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Celkem</strong></td>
                <td><strong><?= array_sum(array_column($attendanceTotals, 'total')) ?></strong></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>
