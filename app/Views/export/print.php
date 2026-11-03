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

    <h1 class="print-sheet-title"><?= \App\Core\View::render('export/_logo') ?>Export akcí</h1>
    <div class="print-filter-summary">
        <p>
            <strong>Datum od:</strong> <?= $dateFrom ? \App\Core\View::e(date('j. n. Y', strtotime($dateFrom))) : '—' ?>
            &nbsp;&nbsp;
            <strong>Datum do:</strong> <?= $dateTo ? \App\Core\View::e(date('j. n. Y', strtotime($dateTo))) : '—' ?>
        </p>
        <p>
            <strong>Typy akcí:</strong>
            <?= $allTypesSelected ? 'Všechny' : \App\Core\View::e(implode(', ', $selectedTypeNames)) ?>
        </p>
        <p>
            <strong>Kategorie návštěvníků:</strong>
            <?= $allCategoriesSelected ? 'Všechny' : \App\Core\View::e(implode(', ', $selectedCategoryNames)) ?>
        </p>
        <p>
            <strong>Organizátor:</strong>
            <?= $allOrganizersSelected ? 'Všichni' : \App\Core\View::e(implode(', ', $selectedOrganizerNames)) ?>
        </p>
        <p>
            <strong>Stav akce:</strong>
            <?= $allStatusesSelected ? 'Všechny' : \App\Core\View::e(implode(', ', $selectedStatusNames)) ?>
        </p>
    </div>

    <?php if (empty($rows)): ?>
        <p>Žádné akce neodpovídají zvolenému filtru.</p>
    <?php else: ?>
    <table class="print-table">
        <thead>
            <tr>
                <th>Název akce</th>
                <?php foreach ($columns as $label): ?>
                <th><?= \App\Core\View::e($label) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= \App\Core\View::e($r['name']) ?></td>
                <?php foreach ($columns as $key => $label): ?>
                <td><?= nl2br(\App\Core\View::e((string) ($r[$key] ?? '—'))) ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</body>
</html>
