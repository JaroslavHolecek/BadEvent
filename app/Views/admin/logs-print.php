<?php
$__fmtDate = fn(?string $d): string => $d === null ? '…' : date('j. n. Y', strtotime($d));
$__period  = ($dateFrom === null && $dateTo === null)
    ? 'celé období'
    : $__fmtDate($dateFrom) . ' – ' . $__fmtDate($dateTo);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= \App\Core\View::e($title) ?></title>
    <?= \App\Core\View::render('layout/_styles') ?>
    <style>
        @page { size: A4 portrait; margin: 10mm; }
        body { background: #fff; }
        .print-page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 10mm;
            box-sizing: border-box;
            background: #fff;
        }
        .print-actions { margin-bottom: 1rem; }
        .print-page h1 { color: var(--color-print-accent); margin: 0 0 2mm; font-size: 1.4rem; }
        .log-meta { margin: 0 0 4mm; font-size: .8rem; color: var(--color-print-text-2); }
        .log-print-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: .68rem; }
        .log-print-table th, .log-print-table td {
            border: 1px solid var(--color-print-accent);
            padding: 1mm 1.5mm;
            vertical-align: top;
            text-align: left;
            overflow-wrap: anywhere;
        }
        .log-print-table thead { display: table-header-group; } /* repeat header on every printed page */
        .log-print-table tr { break-inside: avoid; }
        .log-print-table .col-time  { width: 17%; }
        .log-print-table .col-user  { width: 15%; }
        .log-print-table .col-ent   { width: 10%; }
        .log-print-table .col-id    { width: 6%; }
        .log-print-table .col-op    { width: 12%; }
        .log-print-table .col-data  { width: 40%; }
        .log-data { font-family: monospace; font-size: .62rem; }
        .log-num { text-align: right; }
        .log-empty { font-style: italic; color: var(--color-text-muted); }
        @media print {
            .print-actions { display: none; }
            .print-page { width: auto; min-height: 0; margin: 0; padding: 0; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
<div class="print-page">
    <div class="print-actions">
        <button type="button" class="btn btn-primary" onclick="window.print()">Tisk</button>
    </div>
    <h1 class="print-sheet-title"><?= \App\Core\View::render('export/_logo') ?><?= \App\Core\View::e($title) ?></h1>
    <p class="log-meta">
        Období: <?= \App\Core\View::e($__period) ?> · Počet záznamů: <?= count($rows) ?> · Vytištěno: <?= date('j. n. Y H:i') ?>
        <?php if ($truncated): ?><br><strong>Pozor: zobrazeno pouze posledních <?= (int) $limit ?> záznamů — zužte časové období.</strong><?php endif; ?>
    </p>
    <table class="log-print-table">
        <colgroup>
            <col class="col-time"><col class="col-user"><col class="col-ent"><col class="col-id"><col class="col-op"><col class="col-data">
        </colgroup>
        <thead>
            <tr><th>Čas</th><th>Uživatel</th><th>Entita</th><th>ID</th><th>Operace</th><th>Data</th></tr>
        </thead>
        <tbody>
            <?= \App\Core\View::render('admin/_log-rows', ['rows' => $rows]) ?>
        </tbody>
    </table>
</div>
</body>
</html>
