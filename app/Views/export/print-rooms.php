<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= \App\Core\View::e($title) ?></title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">
    <style>
        @page { size: A4; margin: 7.5mm 15mm 15mm; }
        :root { --print-margin: 15mm; }
        body { background: #fff; }
        .print-page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 7.5mm 15mm 15mm;
            box-sizing: border-box;
            background: #fff;
        }
        .print-actions { margin-bottom: 1rem; }
        .print-filter-summary { margin-bottom: 1.5rem; font-size: .9rem; }
        .print-filter-summary p { margin: .2rem 0; }
        .room-page { page-break-after: always; }
        .room-page:last-child { page-break-after: auto; }
        .room-page h1 { margin-bottom: .75rem; }
        .print-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
        .print-table th, .print-table td {
            border: 1px solid #333;
            padding: .3rem .4rem;
            text-align: left;
            vertical-align: top;
        }
        .print-table th { background: #f2f2f2; }
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

    <?php if (empty($places)): ?>
        <h1 class="print-sheet-title"><?= \App\Core\View::render('export/_logo') ?>Přehled akcí v místnostech</h1>
        <div class="print-filter-summary">
            <p>
                <strong>Datum od:</strong> <?= $dateFrom ? \App\Core\View::e(date('j. n. Y', strtotime($dateFrom))) : '—' ?>
                &nbsp;&nbsp;
                <strong>Datum do:</strong> <?= $dateTo ? \App\Core\View::e(date('j. n. Y', strtotime($dateTo))) : '—' ?>
            </p>
        </div>
        <p>V daném období nemá žádná místnost naplánovanou akci.</p>
    <?php else: ?>
        <?php foreach ($places as $place): ?>
        <div class="room-page">
            <h1 class="print-sheet-title"><?= \App\Core\View::render('export/_logo') ?>
                Akce v místnosti
                <span class="print-sheet-subtitle"><?= \App\Core\View::e($place['name']) ?><?php if ($place['abbreviation']): ?> (<?= \App\Core\View::e($place['abbreviation']) ?>)<?php endif; ?></span>
            </h1>
            <div class="print-filter-summary">
                <p>
                    <strong>Datum od:</strong> <?= $dateFrom ? \App\Core\View::e(date('j. n. Y', strtotime($dateFrom))) : '—' ?>
                    &nbsp;&nbsp;
                    <strong>Datum do:</strong> <?= $dateTo ? \App\Core\View::e(date('j. n. Y', strtotime($dateTo))) : '—' ?>
                </p>
            </div>
            <table class="print-table">
                <thead>
                    <tr>
                        <th>Název akce</th>
                        <th>Datum a čas akce</th>
                        <th>Místnost blokována</th>
                        <th>Organizátoři</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($place['actions'] as $action): ?>
                    <tr>
                        <td><?= \App\Core\View::e($action['name']) ?></td>
                        <td><?= nl2br(\App\Core\View::e($action['date_range'])) ?></td>
                        <td><?= nl2br(\App\Core\View::e($action['staff_range'])) ?></td>
                        <td><?= \App\Core\View::e($action['organizers']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
