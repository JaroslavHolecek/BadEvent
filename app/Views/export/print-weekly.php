<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= \App\Core\View::e($title) ?></title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">
    <style>
        @page { size: A4 landscape; margin: 5mm 10mm 10mm; }
        :root { --print-margin: 10mm; }
        body { background: #fff; }
        .print-page {
            width: 297mm;
            min-height: 210mm;
            margin: 0 auto;
            padding: 5mm 10mm 10mm;
            box-sizing: border-box;
            background: #fff;
        }
        .print-actions { margin-bottom: 1rem; }
        .week-sheet { page-break-after: always; break-after: page; margin-bottom: 12mm; }
        .week-sheet:last-of-type { page-break-after: auto; break-after: auto; }
        .week-sheet h1 { text-align: center; color: #1e3a52; margin: 0 0 4mm; font-size: 1.6rem; }
        .week-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: .72rem; }
        .week-table th, .week-table td { width: calc(100% / 7); border: 1px solid #1e3a52; vertical-align: top; }
        .week-table thead th {
            background: #fff;
            color: #000;
            text-align: center;
            padding: 2mm 1mm;
            text-transform: uppercase;
            letter-spacing: .03em;
        }
        .week-table thead th span { display: block; font-weight: 600; text-transform: none; }
        .week-table tbody td { height: 135mm; padding: 2mm 1.5mm; }
        /* No background colors anywhere: browsers leave them out when printing, so the preview
           shows the table exactly as it will print (text colors do print and stay). */
        .plan-item { margin-bottom: 3mm; line-height: 1.25; break-inside: avoid; }
        .plan-time { font-weight: 700; color: var(--color-primary); }
        .plan-name { font-weight: 700; }
        .plan-detail { color: #4b5563; }
        .plan-empty { margin: 0; color: #6b7280; font-style: italic; }
        .plan-note { margin-top: 3mm; font-size: .7rem; color: #4b5563; }
        @media print {
            .print-actions { display: none; }
            .print-page { width: auto; min-height: 0; margin: 0; padding: 0; }
            .week-sheet { margin-bottom: 0; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
<div class="print-page">
    <div class="print-actions">
        <button type="button" class="btn btn-primary" onclick="window.print()">Tisk</button>
    </div>

    <?php foreach ($weeks as $week): ?>
    <section class="week-sheet">
        <h1 class="print-sheet-title"><?= \App\Core\View::render('export/_logo') ?><?= \App\Core\View::e($week['title']) ?></h1>
        <table class="week-table">
            <thead>
                <tr>
                    <?php foreach ($week['days'] as $day): ?>
                    <th><?= \App\Core\View::e($day['name']) ?><span><?= \App\Core\View::e($day['date']) ?></span></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach ($week['days'] as $day): ?>
                    <td<?= $day['inRange'] ? '' : ' class="out-of-range"' ?>>
                        <?php if (!$day['inRange']): ?>
                        <p class="plan-empty">Tento den není v zadaném rozsahu</p>
                        <?php elseif (empty($day['actions'])): ?>
                        <p class="plan-empty">V tento den není žádná akce</p>
                        <?php endif; ?>
                        <?php foreach ($day['actions'] as $a): ?>
                        <div class="plan-item">
                            <?php if ($a['public_time'] !== null): ?>
                            <div class="plan-time"><?= \App\Core\View::e($a['public_time']) ?></div>
                            <?php endif; ?>
                            <div class="plan-name"><?= \App\Core\View::e($a['name']) ?></div>
                            <?php if ($a['staff_time'] !== null): ?>
                            <div class="plan-detail">Personál: <?= \App\Core\View::e($a['staff_time']) ?></div>
                            <?php endif; ?>
                            <?php if ($a['places'] !== ''): ?>
                            <div class="plan-detail"><?= \App\Core\View::e($a['places']) ?></div>
                            <?php endif; ?>
                            <div class="plan-detail">Org.: <?= \App\Core\View::e($a['organizers']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
        <p class="plan-note"><strong>Provozní poznámka:</strong> Uvedené časy pro veřejnost jsou časy konání akcí. Místnosti mohou být blokovány dříve nebo déle – při plánování služeb zohledněte čas pro personál.</p>
    </section>
    <?php endforeach; ?>
</div>
</body>
</html>
