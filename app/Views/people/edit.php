<div class="page-header">
    <h1><?= \App\Core\View::e($title) ?></h1>
    <a href="<?= url('/people/' . (int) $person['id']) ?>" class="btn btn-ghost">← Zpět na detail</a>
</div>

<div class="card">
    <form method="POST" action="<?= url('/people/' . (int) $person['id']) ?>" id="person-edit-form"
          data-original='<?= htmlspecialchars(json_encode([
              'full_name'  => $person['full_name'],
              'short_name' => $person['short_name'] ?? '',
              'note'       => $person['note'] ?? '',
          ]), ENT_QUOTES, 'UTF-8') ?>'>
        <div class="form-group required">
            <label for="full_name">Celé jméno <span class="req">*</span></label>
            <input type="text" name="full_name" id="full_name" required maxlength="255"
                   value="<?= \App\Core\View::e($person['full_name']) ?>">
        </div>
        <div class="form-group">
            <label for="short_name">Krátké jméno</label>
            <input type="text" name="short_name" id="short_name" maxlength="100"
                   value="<?= \App\Core\View::e($person['short_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="note">Poznámka</label>
            <textarea name="note" id="note" rows="4"><?= \App\Core\View::e($person['note'] ?? '') ?></textarea>
        </div>

        <div id="changed-indicator" class="changed-banner" style="display:none">
            Formulář obsahuje neuložené změny.
        </div>

        <div class="form-footer">
            <button type="submit" class="btn btn-primary" id="submit-btn" disabled>Uložit změny</button>
            <a href="<?= url('/people/' . (int) $person['id']) ?>" class="btn btn-ghost">Zrušit</a>
        </div>
    </form>
</div>

<script>
initFormTracker('person-edit-form');
</script>
