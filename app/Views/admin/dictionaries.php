<?php
/** @var array $types */
/** @var string $selectedType */
/** @var array $items */
/** @var int $selectedIncomeTypeId */
/** @var array $incomeSubtypes */
/** @var array $places */

$__maxLengths = [
    'contact_types'      => ['name' => 100, 'abbreviation' => 20],
    'action_types'       => ['name' => 100, 'abbreviation' => 30],
    'action_topics'      => ['name' => 100, 'abbreviation' => 30],
    'action_formats'     => ['name' => 100, 'abbreviation' => 30],
    'visitor_types'      => ['name' => 100],
    'visitor_categories' => ['name' => 100],
    'cost_types'         => ['name' => 100],
    'income_types'       => ['name' => 100],
];
?>
<div class="page-header">
    <h1><?= \App\Core\View::e($title) ?></h1>
</div>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="card">
            <div class="card-header"><h2>Číselníky</h2></div>
            <ul class="role-list">
                <?php foreach ($types as $type => $meta): ?>
                <li>
                    <a href="<?= url('/admin/dictionaries?type=' . $type) ?>"
                       class="role-link<?= $selectedType === $type ? ' active' : '' ?>">
                        <?= \App\Core\View::e($meta['label']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
                <li>
                    <a href="<?= url('/admin/dictionaries?type=currencies') ?>"
                       class="role-link<?= $selectedType === 'currencies' ? ' active' : '' ?>">Měny</a>
                </li>
                <li>
                    <a href="<?= url('/admin/dictionaries?type=places') ?>"
                       class="role-link<?= $selectedType === 'places' ? ' active' : '' ?>">Místnosti</a>
                </li>
            </ul>
        </div>
    </div>

    <div class="admin-main">
        <?php if ($selectedType === 'currencies'): ?>

            <div class="card">
                <div class="card-header"><h2>Nová měna</h2></div>
                <p class="hint">Měny, ve kterých se u akcí zaznamenávají příjmy a výdaje, např. Korun českých, Euro.</p>
                <form method="POST" action="<?= url('/admin/currencies') ?>">
                    <div class="form-group required">
                        <label for="currency_code">Kód <span class="req">*</span></label>
                        <input type="text" name="code" id="currency_code" required maxlength="3" placeholder="CZK">
                    </div>
                    <div class="form-group required">
                        <label for="currency_name">Název <span class="req">*</span></label>
                        <input type="text" name="name" id="currency_name" required maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="currency_symbol">Symbol</label>
                        <input type="text" name="symbol" id="currency_symbol" maxlength="10">
                    </div>
                    <div class="form-group">
                        <label for="currency_decimal_places">Počet desetinných míst</label>
                        <input type="number" name="decimal_places" id="currency_decimal_places" min="0" max="4" value="2">
                    </div>
                    <div class="form-footer">
                        <button type="submit" class="btn btn-primary">Vytvořit měnu</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header"><h2>Měny</h2></div>
                <table class="table">
                    <thead><tr><th>Kód</th><th>Název</th><th>Symbol</th><th>Stav</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td><?= \App\Core\View::e($it['code']) ?></td>
                            <td><?= \App\Core\View::e($it['name']) ?></td>
                            <td><?= \App\Core\View::e($it['symbol'] ?? '') ?></td>
                            <td>
                                <span class="perm-badge <?= $it['deactivated_at'] === null ? 'perm-granted' : 'perm-revoked' ?>">
                                    <?= $it['deactivated_at'] === null ? 'Aktivní' : 'Deaktivovaná' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($it['deactivated_at'] === null): ?>
                                <form method="POST" action="<?= url('/admin/currencies/' . (int) $it['id'] . '/deactivate') ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Deaktivovat</button>
                                </form>
                                <?php else: ?>
                                <form method="POST" action="<?= url('/admin/currencies/' . (int) $it['id'] . '/activate') ?>">
                                    <button type="submit" class="btn btn-sm btn-success">Aktivovat</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($selectedType === 'places'): ?>

            <div class="card">
                <div class="card-header"><h2>Nová místnost</h2></div>
                <p class="hint">Fyzické prostory, které lze pro akci zabrat/vyžádat, např. sál, klubovna, zahrada.</p>
                <form method="POST" action="<?= url('/places') ?>">
                    <div class="form-group required">
                        <label for="place_name">Název <span class="req">*</span></label>
                        <input type="text" name="name" id="place_name" required maxlength="150">
                    </div>
                    <div class="form-group">
                        <label for="place_abbreviation">Zkratka</label>
                        <input type="text" name="abbreviation" id="place_abbreviation" maxlength="30">
                    </div>
                    <div class="form-group">
                        <label for="place_capacity">Kapacita</label>
                        <input type="number" name="capacity" id="place_capacity" min="0">
                    </div>
                    <div class="form-group">
                        <label for="place_note">Poznámka</label>
                        <textarea name="note" id="place_note" rows="2"></textarea>
                    </div>
                    <?php if (!empty($placeManagerCandidates)): ?>
                    <div class="form-group">
                        <label>Správci místnosti</label>
                        <div class="checkbox-grid">
                            <?php foreach ($placeManagerCandidates as $u): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="manager_id[]" value="<?= (int) $u['id'] ?>">
                                <?= \App\Core\View::e($u['full_name'] ?? $u['short_name'] ?? ('Uživatel #' . $u['id'])) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="form-footer">
                        <button type="submit" class="btn btn-primary">Vytvořit místnost</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header"><h2>Místnosti</h2></div>
                <table class="table">
                    <thead><tr><th>Název</th><th>Zkratka</th><th>Kapacita</th><th>Poznámka</th><th>Správci</th><th>Stav</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($places as $p): ?>
                        <tr>
                            <td><?= \App\Core\View::e($p['name']) ?></td>
                            <td><?= \App\Core\View::e($p['abbreviation'] ?? '') ?></td>
                            <td><?= \App\Core\View::e($p['capacity'] ?? '') ?></td>
                            <td><?= nl2br(\App\Core\View::e($p['note'] ?? '')) ?></td>
                            <td><?= \App\Core\View::e($p['managers'] ?? '') ?></td>
                            <td>
                                <span class="perm-badge <?= $p['deactivated_at'] === null ? 'perm-granted' : 'perm-revoked' ?>">
                                    <?= $p['deactivated_at'] === null ? 'Aktivní' : 'Deaktivovaná' ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= url('/places/' . (int) $p['id'] . '/edit') ?>" class="btn btn-sm btn-ghost">Upravit</a>
                                <?php if ($p['deactivated_at'] === null): ?>
                                <form method="POST" action="<?= url('/places/' . (int) $p['id'] . '/deactivate') ?>" style="display:inline-block">
                                    <button type="submit" class="btn btn-sm btn-danger">Deaktivovat</button>
                                </form>
                                <?php else: ?>
                                <form method="POST" action="<?= url('/places/' . (int) $p['id'] . '/activate') ?>" style="display:inline-block">
                                    <button type="submit" class="btn btn-sm btn-success">Aktivovat</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <?php $__hasAbbr = \App\Repositories\DictionaryRepository::hasAbbreviation($selectedType); ?>
            <div class="card">
                <div class="card-header"><h2>Nová položka: <?= \App\Core\View::e($types[$selectedType]['label']) ?></h2></div>
                <?php if (!empty($types[$selectedType]['help'])): ?>
                <p class="hint"><?= \App\Core\View::e($types[$selectedType]['help']) ?></p>
                <?php endif; ?>
                <form method="POST" action="<?= url('/admin/dictionaries/' . $selectedType) ?>">
                    <div class="form-group required">
                        <label for="dict_name">Název <span class="req">*</span></label>
                        <input type="text" name="name" id="dict_name" required maxlength="<?= (int) ($__maxLengths[$selectedType]['name'] ?? 100) ?>">
                    </div>
                    <?php if ($__hasAbbr): ?>
                    <div class="form-group">
                        <label for="dict_abbreviation">Zkratka</label>
                        <input type="text" name="abbreviation" id="dict_abbreviation" maxlength="<?= (int) ($__maxLengths[$selectedType]['abbreviation'] ?? 30) ?>">
                    </div>
                    <?php endif; ?>
                    <div class="form-footer">
                        <button type="submit" class="btn btn-primary">Vytvořit</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header"><h2><?= \App\Core\View::e($types[$selectedType]['label']) ?></h2></div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Název</th>
                            <?php if ($__hasAbbr): ?><th>Zkratka</th><?php endif; ?>
                            <th>Stav</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td>
                                <?= \App\Core\View::e($it['name']) ?>
                                <?php if ($selectedType === 'income_types'): ?>
                                <a href="<?= url('/admin/dictionaries?type=income_types&income_type_id=' . (int) $it['id']) ?>" class="btn btn-sm">Podtypy</a>
                                <?php endif; ?>
                            </td>
                            <?php if ($__hasAbbr): ?><td><?= \App\Core\View::e($it['abbreviation'] ?? '') ?></td><?php endif; ?>
                            <td>
                                <span class="perm-badge <?= $it['deactivated_at'] === null ? 'perm-granted' : 'perm-revoked' ?>">
                                    <?= $it['deactivated_at'] === null ? 'Aktivní' : 'Deaktivovaná' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($it['deactivated_at'] === null): ?>
                                <form method="POST" action="<?= url('/admin/dictionaries/' . $selectedType . '/' . (int) $it['id'] . '/deactivate') ?>" style="display:inline-block">
                                    <button type="submit" class="btn btn-sm btn-danger">Deaktivovat</button>
                                </form>
                                <?php else: ?>
                                <form method="POST" action="<?= url('/admin/dictionaries/' . $selectedType . '/' . (int) $it['id'] . '/activate') ?>" style="display:inline-block">
                                    <button type="submit" class="btn btn-sm btn-success">Aktivovat</button>
                                </form>
                                <?php endif; ?>
                                <details class="inline-edit">
                                    <summary class="btn btn-sm btn-ghost">Upravit</summary>
                                    <form method="POST" action="<?= url('/admin/dictionaries/' . $selectedType . '/' . (int) $it['id'] . '/update') ?>"
                                          class="confirm-edit-form" data-confirm-message="Úpravou změníte text nejen pro budoucí položky, ale TAKÉ PRO VŠECHNY MINULÉ.">
                                        <input type="text" name="name" required placeholder="Název"
                                               maxlength="<?= (int) ($__maxLengths[$selectedType]['name'] ?? 100) ?>" value="<?= \App\Core\View::e($it['name']) ?>">
                                        <?php if ($__hasAbbr): ?>
                                        <input type="text" name="abbreviation" placeholder="Zkratka"
                                               maxlength="<?= (int) ($__maxLengths[$selectedType]['abbreviation'] ?? 30) ?>" value="<?= \App\Core\View::e($it['abbreviation'] ?? '') ?>">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-sm btn-warning">Uložit</button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($selectedType === 'income_types' && $selectedIncomeTypeId > 0): ?>
            <?php
                $__selectedIncomeType = null;
                foreach ($items as $it) {
                    if ((int) $it['id'] === $selectedIncomeTypeId) {
                        $__selectedIncomeType = $it;
                        break;
                    }
                }
            ?>
            <div class="card">
                <div class="card-header"><h2>Podtyp příjmu <?= \App\Core\View::e($__selectedIncomeType['name'] ?? '') ?></h2></div>
                <form method="POST" action="<?= url('/admin/income-subtypes/' . $selectedIncomeTypeId) ?>">
                    <div class="form-group required">
                        <label for="subtype_name">Nový podtyp <span class="req">*</span></label>
                        <input type="text" name="name" id="subtype_name" required maxlength="100">
                    </div>
                    <div class="form-footer">
                        <button type="submit" class="btn btn-primary">Vytvořit podtyp</button>
                    </div>
                </form>
                <table class="table">
                    <thead><tr><th>Název</th><th>Stav</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($incomeSubtypes as $st): ?>
                        <tr>
                            <td><?= \App\Core\View::e($st['name']) ?></td>
                            <td>
                                <span class="perm-badge <?= $st['deactivated_at'] === null ? 'perm-granted' : 'perm-revoked' ?>">
                                    <?= $st['deactivated_at'] === null ? 'Aktivní' : 'Deaktivovaný' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($st['deactivated_at'] === null): ?>
                                <form method="POST" action="<?= url('/admin/income-subtypes/' . (int) $st['id'] . '/deactivate') ?>" style="display:inline-block">
                                    <input type="hidden" name="income_type_id" value="<?= $selectedIncomeTypeId ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Deaktivovat</button>
                                </form>
                                <?php else: ?>
                                <form method="POST" action="<?= url('/admin/income-subtypes/' . (int) $st['id'] . '/activate') ?>" style="display:inline-block">
                                    <input type="hidden" name="income_type_id" value="<?= $selectedIncomeTypeId ?>">
                                    <button type="submit" class="btn btn-sm btn-success">Aktivovat</button>
                                </form>
                                <?php endif; ?>
                                <details class="inline-edit">
                                    <summary class="btn btn-sm btn-ghost">Upravit</summary>
                                    <form method="POST" action="<?= url('/admin/income-subtypes/' . (int) $st['id'] . '/update') ?>"
                                          class="confirm-edit-form" data-confirm-message="Úpravou změníte text nejen pro budoucí položky, ale TAKÉ PRO VŠECHNY MINULÉ.">
                                        <input type="hidden" name="income_type_id" value="<?= $selectedIncomeTypeId ?>">
                                        <input type="text" name="name" required placeholder="Název" maxlength="100" value="<?= \App\Core\View::e($st['name']) ?>">
                                        <button type="submit" class="btn btn-sm btn-warning">Uložit</button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
