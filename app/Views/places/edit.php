<div class="page-header">
    <h1><?= \App\Core\View::e($title) ?></h1>
    <a href="<?= url('/admin/dictionaries?type=places') ?>" class="btn btn-ghost">← Zpět</a>
</div>

<div class="card">
    <form method="POST" action="<?= url('/places/' . (int) $place['id']) ?>">
        <div class="form-group required">
            <label for="place_name">Název <span class="req">*</span></label>
            <input type="text" name="name" id="place_name" required maxlength="150"
                   value="<?= \App\Core\View::e($place['name']) ?>">
        </div>
        <div class="form-group">
            <label for="place_abbreviation">Zkratka</label>
            <input type="text" name="abbreviation" id="place_abbreviation" maxlength="30"
                   value="<?= \App\Core\View::e($place['abbreviation'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="place_capacity">Kapacita</label>
            <input type="number" name="capacity" id="place_capacity" min="0"
                   value="<?= $place['capacity'] !== null ? (int) $place['capacity'] : '' ?>">
        </div>
        <div class="form-group">
            <label for="place_note">Poznámka</label>
            <textarea name="note" id="place_note" rows="3"><?= \App\Core\View::e($place['note'] ?? '') ?></textarea>
        </div>

        <?php if (!empty($managerCandidates)): ?>
        <div class="form-group">
            <label>Správci místnosti</label>
            <div class="checkbox-grid">
                <?php foreach ($managerCandidates as $u): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="manager_id[]" value="<?= (int) $u['id'] ?>"
                           <?= in_array((int) $u['id'], $assignedManagerIds, true) ? 'checked' : '' ?>>
                    <?= \App\Core\View::e($u['full_name'] ?? $u['short_name'] ?? ('Uživatel #' . $u['id'])) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <p class="hint">Žádný uživatel nemá roli „Správce místnosti“.</p>
        <?php endif; ?>

        <div class="form-footer">
            <button type="submit" class="btn btn-primary">Uložit změny</button>
            <a href="<?= url('/admin/dictionaries?type=places') ?>" class="btn btn-ghost">Zrušit</a>
        </div>
    </form>
</div>
