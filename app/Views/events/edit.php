<div class="page-header">
    <h1><?= \App\Core\View::e($title) ?></h1>
    <a href="<?= url('/events/' . (int) $event['id']) ?>" class="btn btn-ghost">← Zpět na detail</a>
</div>

<div class="card">
    <form method="POST" action="<?= url('/events/' . (int) $event['id']) ?>" id="event-edit-form"
          data-original='<?= htmlspecialchars(json_encode(['name' => $event['name'], 'note' => $event['note'] ?? '']), ENT_QUOTES, 'UTF-8') ?>'>
        <div class="form-group required">
            <label for="name">Název události <span class="req">*</span></label>
            <input type="text" name="name" id="name" required maxlength="255"
                   value="<?= \App\Core\View::e($event['name']) ?>">
        </div>
        <div class="form-group">
            <label for="note">Poznámka</label>
            <textarea name="note" id="note" rows="4"><?= \App\Core\View::e($event['note'] ?? '') ?></textarea>
        </div>

        <div id="changed-indicator" class="changed-banner" style="display:none">
            Formulář obsahuje neuložené změny.
        </div>

        <div class="form-footer">
            <button type="submit" class="btn btn-primary" id="submit-btn" disabled>Uložit změny</button>
            <a href="<?= url('/events/' . (int) $event['id']) ?>" class="btn btn-ghost">Zrušit</a>
        </div>
    </form>
</div>

<script>
initFormTracker('event-edit-form');
</script>
