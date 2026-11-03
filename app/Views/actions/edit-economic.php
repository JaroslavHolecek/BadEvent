<?php
$renderIncomeRow = function (array $incomeTypes, array $currencies, ?array $row = null): string {
    $typeId     = $row['income_type_id'] ?? null;
    $subtypeId  = $row['income_subtype_id'] ?? null;
    $currencyId = $row['currency_id'] ?? null;
    $amount     = $row['amount'] ?? '';
    $reference  = $row['reference_number'] ?? '';
    $note       = $row['note'] ?? '';
    $e          = fn($v) => \App\Core\View::e((string) $v);

    ob_start(); ?>
    <div class="finance-row" data-form-row data-subtype="<?= $e($subtypeId ?? '') ?>">
        <select name="income_type_id[]" class="income-type-select">
            <option value="">— typ —</option>
            <?php foreach ($incomeTypes as $t): ?>
            <option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === (int) $typeId ? 'selected' : '' ?>><?= $e($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="income_subtype_id[]" class="income-subtype-select">
            <option value="">— podtyp —</option>
        </select>
        <select name="income_currency_id[]">
            <?php foreach ($currencies as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $currencyId ? 'selected' : '' ?>><?= $e($c['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" step="0.01" min="0" name="income_amount[]" placeholder="Částka" value="<?= $e($amount) ?>">
        <input type="text" name="income_reference[]" placeholder="Číslo dokladu" value="<?= $e($reference) ?>">
        <input type="text" name="income_note[]" placeholder="Poznámka" value="<?= $e($note) ?>">
        <button type="button" class="btn btn-sm btn-danger remove-row-btn">✕</button>
    </div>
    <?php
    return (string) ob_get_clean();
};

$renderCostRow = function (array $costTypes, array $costObjects, array $currencies, ?array $row = null): string {
    $typeId     = $row['cost_type_id'] ?? null;
    $objectId   = $row['cost_object_id'] ?? null;
    $currencyId = $row['currency_id'] ?? null;
    $amount     = $row['amount'] ?? '';
    $reference  = $row['reference_number'] ?? '';
    $note       = $row['note'] ?? '';
    $e          = fn($v) => \App\Core\View::e((string) $v);

    ob_start(); ?>
    <div class="finance-row finance-row-cost" data-form-row>
        <select name="cost_type_id[]">
            <option value="">— typ —</option>
            <?php foreach ($costTypes as $t): ?>
            <option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === (int) $typeId ? 'selected' : '' ?>><?= $e($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="cost_object_id[]">
            <option value="">— za co —</option>
            <?php foreach ($costObjects as $o): ?>
            <option value="<?= (int) $o['id'] ?>" <?= (int) $o['id'] === (int) $objectId ? 'selected' : '' ?>><?= $e($o['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="cost_currency_id[]">
            <?php foreach ($currencies as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $currencyId ? 'selected' : '' ?>><?= $e($c['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" step="0.01" min="0" name="cost_amount[]" placeholder="Částka" value="<?= $e($amount) ?>">
        <input type="text" name="cost_reference[]" placeholder="Číslo dokladu" value="<?= $e($reference) ?>">
        <input type="text" name="cost_note[]" placeholder="Poznámka" value="<?= $e($note) ?>">
        <button type="button" class="btn btn-sm btn-danger remove-row-btn">✕</button>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>

<div class="page-header">
    <h1><?= \App\Core\View::e($title) ?></h1>
    <a href="<?= url('/actions/' . (int) $action['id']) ?>" class="btn btn-ghost">← Zpět na detail</a>
</div>

<?php if ($action['status_code'] === 'closed'): ?>
<div class="alert alert-warning">Pozor, zasahujete do uzavřené akce.</div>
<?php endif; ?>

<div class="card">
    <form method="POST" action="<?= url('/actions/' . (int) $action['id'] . '/edit-economic') ?>" id="economic-edit-form">
        <div class="form-section">
            <h2>Příjmy</h2>
            <div id="income-rows" class="finance-rows">
                <?php foreach ($incomes as $i): ?>
                    <?= $renderIncomeRow($incomeTypes, $currencies, $i) ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-ghost" id="add-income-row" data-add-row-for="income_type_id[]">+ Přidat příjem</button>
        </div>

        <div class="form-section">
            <h2>Výdaje</h2>
            <div id="cost-rows" class="finance-rows">
                <?php foreach ($costs as $c): ?>
                    <?= $renderCostRow($costTypes, $costObjects, $currencies, $c) ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-ghost" id="add-cost-row" data-add-row-for="cost_type_id[]">+ Přidat výdaj</button>
        </div>

        <div class="form-footer">
            <button type="submit" class="btn btn-primary">Uložit</button>
            <a href="<?= url('/actions/' . (int) $action['id']) ?>" class="btn btn-ghost">Ponechat beze změn</a>
        </div>
    </form>
</div>

<script>
const INCOME_SUBTYPES = <?= json_encode($incomeSubtypes, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const emptyIncomeRowHtml = <?= json_encode($renderIncomeRow($incomeTypes, $currencies), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const emptyCostRowHtml   = <?= json_encode($renderCostRow($costTypes, $costObjects, $currencies), JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function refreshSubtypeOptions(row) {
    const typeSelect = row.querySelector('.income-type-select');
    const subtypeSelect = row.querySelector('.income-subtype-select');
    if (!typeSelect || !subtypeSelect) return;

    const wantedSubtypeId = row.dataset.subtype || '';
    const typeId = parseInt(typeSelect.value, 10);

    subtypeSelect.innerHTML = '<option value="">— podtyp —</option>';
    INCOME_SUBTYPES
        .filter(s => s.income_type_id == typeId)
        .forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name;
            if (String(s.id) === String(wantedSubtypeId)) opt.selected = true;
            subtypeSelect.appendChild(opt);
        });
    row.dataset.subtype = '';
}

function wireIncomeRow(row) {
    refreshSubtypeOptions(row);
    row.querySelector('.income-type-select')?.addEventListener('change', () => refreshSubtypeOptions(row));
}

function wireRemoveButton(row) {
    row.querySelector('.remove-row-btn')?.addEventListener('click', () => row.remove());
}

document.querySelectorAll('#income-rows .finance-row').forEach(row => {
    wireIncomeRow(row);
    wireRemoveButton(row);
});
document.querySelectorAll('#cost-rows .finance-row').forEach(wireRemoveButton);

document.getElementById('add-income-row').addEventListener('click', () => {
    const container = document.getElementById('income-rows');
    const wrapper = document.createElement('div');
    wrapper.innerHTML = emptyIncomeRowHtml;
    const row = wrapper.firstElementChild;
    container.appendChild(row);
    wireIncomeRow(row);
    wireRemoveButton(row);
});

document.getElementById('add-cost-row').addEventListener('click', () => {
    const container = document.getElementById('cost-rows');
    const wrapper = document.createElement('div');
    wrapper.innerHTML = emptyCostRowHtml;
    const row = wrapper.firstElementChild;
    container.appendChild(row);
    wireRemoveButton(row);
});
</script>
