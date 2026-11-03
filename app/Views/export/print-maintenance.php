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
        .print-table { width: 100%; border-collapse: collapse; font-size: .78rem; table-layout: fixed; }
        .print-table th, .print-table td {
            border: 1px solid #333;
            padding: .3rem .4rem;
            text-align: left;
            vertical-align: top;
            overflow-wrap: break-word;
        }
        .print-table th { background: #f2f2f2; }
        .print-table col.col-organizers { width: 18ch; }
        .print-table col.col-places { width: 18%; }
        .maintenance-room { margin-bottom: .4rem; }
        .maintenance-room:last-child { margin-bottom: 0; }
        .maintenance-room .hint { font-size: .72rem; color: #555; }
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

    <h1 class="print-sheet-title"><?= \App\Core\View::render('export/_logo') ?>Export pro údržbu</h1>
    <div class="print-filter-summary">
        <p>
            <strong>Datum od:</strong> <?= $dateFrom ? \App\Core\View::e(date('j. n. Y', strtotime($dateFrom))) : '—' ?>
            &nbsp;&nbsp;
            <strong>Datum do:</strong> <?= $dateTo ? \App\Core\View::e(date('j. n. Y', strtotime($dateTo))) : '—' ?>
        </p>
    </div>

    <?php if (empty($rows)): ?>
        <p>V daném období není žádná akce se zabranou místností.</p>
    <?php else: ?>
    <table class="print-table">
        <colgroup>
            <col>
            <col>
            <col>
            <col class="col-organizers">
            <col class="col-places">
        </colgroup>
        <thead>
            <tr>
                <th>Název akce</th>
                <th>Od-do pro veřejnost</th>
                <th>Blokace místnosti</th>
                <th>Organizátoři</th>
                <th>Místnosti</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= \App\Core\View::e($r['name']) ?></td>
                <td><?= nl2br(\App\Core\View::e($r['date_range'])) ?></td>
                <td><?= nl2br(\App\Core\View::e($r['staff_range'])) ?></td>
                <td><?= nl2br(\App\Core\View::e($r['organizers'])) ?></td>
                <td>
                    <?php if (empty($r['places'])): ?>
                        —
                    <?php else: ?>
                        <?php foreach ($r['places'] as $p): ?>
                        <div class="maintenance-room">
                            <strong><?= \App\Core\View::e($p['name']) ?></strong>
                            <?php if (!empty($p['room_note'])): ?>
                            <div><?= nl2br(\App\Core\View::e($p['room_note'])) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($p['action_place_note'])): ?>
                            <div><?= nl2br(\App\Core\View::e($p['action_place_note'])) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</body>
</html>
