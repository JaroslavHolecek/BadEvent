<div class="page-header">
    <h1>Export</h1>
</div>

<div class="card">
    <form id="export-filter-form" method="GET" action="<?= url('/export/print') ?>" target="_blank">
        <div class="form-section">
            <h2>Údaje k zahrnutí</h2>
            <p class="hint">Název akce se zobrazí vždy, ostatní údaje si vyberte.</p>
            <div class="checkbox-grid">
                <?php foreach ($columns as $key => $label): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="column[]" value="<?= \App\Core\View::e($key) ?>" checked>
                    <?= \App\Core\View::e($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-section">
            <h2>Časové období</h2>
            <p class="hint">Prázdné pole znamená nefiltrovat v daném směru času.</p>
            <div class="form-row">
                <div class="form-group">
                    <label for="date_from">Datum od</label>
                    <input type="date" name="date_from" id="date_from">
                </div>
                <div class="form-group">
                    <label for="date_to">Datum do</label>
                    <input type="date" name="date_to" id="date_to">
                </div>
            </div>
        </div>

        <div class="form-section">
            <h2>Stav akce</h2>
            <p class="hint">Akce musí být v jednom ze zaškrtnutých stavů. Bez zaškrtnutí se podle stavu nefiltruje (zobrazí se akce ve všech stavech).</p>
            <div class="checkbox-grid">
                <?php foreach ($statuses as $st): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="status_id[]" value="<?= (int) $st['id'] ?>" checked>
                    <?= \App\Core\View::e($st['name']) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-section">
            <h2>Typy akcí</h2>
            <p class="hint">Akce musí mít přiřazený alespoň jeden ze zaškrtnutých typů. Bez zaškrtnutí se podle typu nefiltruje.</p>
            <?php if (empty($actionTypes)): ?>
                <p class="empty-state">Žádné typy akcí nejsou definovány.</p>
            <?php else: ?>
            <div class="checkbox-grid">
                <?php foreach ($actionTypes as $t): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="action_type_id[]" value="<?= (int) $t['id'] ?>" checked>
                    <?= \App\Core\View::e($t['name']) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="form-section">
            <h2>Kategorie návštěvníků</h2>
            <p class="hint">Akce musí mít přiřazenou alespoň jednu ze zaškrtnutých kategorií. Bez zaškrtnutí se podle kategorie nefiltruje.</p>
            <?php if (empty($visitorCategories)): ?>
                <p class="empty-state">Žádné kategorie návštěvníků nejsou definovány.</p>
            <?php else: ?>
            <div class="checkbox-grid">
                <?php foreach ($visitorCategories as $c): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="visitor_category_id[]" value="<?= (int) $c['id'] ?>" checked>
                    <?= \App\Core\View::e($c['name']) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="form-section">
            <h2>Organizátor</h2>
            <p class="hint">Akce musí mít přiřazeného alespoň jednoho ze zaškrtnutých organizátorů. Bez zaškrtnutí se podle organizátora nefiltruje.</p>
            <?php if (empty($organizers)): ?>
                <p class="empty-state">Žádní organizátoři nejsou definováni.</p>
            <?php else: ?>
            <div class="checkbox-grid">
                <?php foreach ($organizers as $o): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="organizer_id[]" value="<?= (int) $o['id'] ?>" checked>
                    <?= \App\Core\View::e($o['full_name'] ?? $o['short_name'] ?? ('Uživatel #' . $o['id'])) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="form-footer">
            <button type="submit" class="btn btn-primary">Exportovat pro tisk</button>
            <button type="submit" formaction="<?= url('/export/excel') ?>" formtarget="_self" class="btn btn-ghost">Exportovat pro Excel</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header"><h2>Specifické exporty</h2></div>
    <form id="specific-export-form" method="GET" action="<?= url('/export/statistika/print') ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="specific_date_from">Datum od</label>
                <input type="date" name="date_from" id="specific_date_from">
            </div>
            <div class="form-group">
                <label for="specific_date_to">Datum do</label>
                <input type="date" name="date_to" id="specific_date_to">
            </div>
        </div>

        <div class="specific-export-row">
            <strong>Statistika</strong>
            <div class="specific-export-actions">
                <button type="submit" formaction="<?= url('/export/statistika/print') ?>" formtarget="_blank" class="btn btn-sm btn-ghost">Export pro tisk</button>
                <button type="submit" formaction="<?= url('/export/statistika/excel') ?>" formtarget="_self" class="btn btn-sm btn-ghost">Export pro Excel</button>
            </div>
        </div>

        <div class="specific-export-row">
            <strong>Plán akcí po týdnech</strong>
            <div class="specific-export-actions">
                <button type="submit" formaction="<?= url('/export/weekly/print') ?>" formtarget="_blank" class="btn btn-sm btn-ghost">Export pro tisk</button>
            </div>
        </div>

        <div class="specific-export-row">
            <strong>Přehled akcí v místnostech</strong>
            <div class="specific-export-actions">
                <button type="submit" formaction="<?= url('/export/rooms/print') ?>" formtarget="_blank" class="btn btn-sm btn-ghost">Export pro tisk</button>
                <button type="submit" formaction="<?= url('/export/rooms/excel') ?>" formtarget="_self" class="btn btn-sm btn-ghost">Export pro Excel</button>
            </div>
        </div>

        <div class="specific-export-row">
            <strong>Přehled akcí pro mediální tým</strong>
            <div class="specific-export-actions">
                <button type="submit" formaction="<?= url('/export/media-team/print') ?>" formtarget="_blank" class="btn btn-sm btn-ghost">Export pro tisk</button>
                <button type="submit" formaction="<?= url('/export/media-team/excel') ?>" formtarget="_self" class="btn btn-sm btn-ghost">Export pro Excel</button>
            </div>
        </div>

        <div class="specific-export-row">
            <strong>Export pro údržbu</strong>
            <div class="specific-export-actions">
                <button type="submit" formaction="<?= url('/export/maintenance/print') ?>" formtarget="_blank" class="btn btn-sm btn-ghost">Export pro tisk</button>
                <button type="submit" formaction="<?= url('/export/maintenance/excel') ?>" formtarget="_self" class="btn btn-sm btn-ghost">Export pro Excel</button>
            </div>
        </div>
    </form>
</div>
<script>
rememberFormState(document.getElementById('export-filter-form'), 'bad-event:export-filter');
rememberFormState(document.getElementById('specific-export-form'), 'bad-event:export-filter-specific');
</script>
