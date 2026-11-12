<?php $__query = http_build_query(['date_from' => $dateFrom ?? '', 'date_to' => $dateTo ?? '']); ?>
<div class="page-header">
    <h1>Logy aplikace</h1>
    <a href="<?= url('/admin/logs/print?' . $__query) ?>" class="btn btn-primary" target="_blank">Export / tisk</a>
</div>

<div class="card">
    <form method="GET" action="<?= url('/admin/logs') ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="date_from">Datum od</label>
                <input type="date" name="date_from" id="date_from" value="<?= \App\Core\View::e($dateFrom ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="date_to">Datum do</label>
                <input type="date" name="date_to" id="date_to" value="<?= \App\Core\View::e($dateTo ?? '') ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Filtrovat</button>
    </form>
    <p class="hint">Prázdné pole znamená nefiltrovat v daném směru času. Bez zadání filtru se zobrazí posledních 7 dní.</p>
</div>

<?php if ($truncated): ?>
<div class="alert alert-warning">Zobrazeno pouze posledních <?= (int) $limit ?> záznamů — pro zobrazení starších zužte časové období.</div>
<?php endif; ?>

<div class="card">
    <table class="table log-table">
        <thead>
            <tr><th>Čas</th><th>Uživatel</th><th>Entita</th><th>ID</th><th>Operace</th><th>Data</th></tr>
        </thead>
        <tbody>
            <?= \App\Core\View::render('admin/_log-rows', ['rows' => $rows]) ?>
        </tbody>
    </table>
</div>
