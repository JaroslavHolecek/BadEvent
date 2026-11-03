<div class="page-header">
    <h1>Nová osoba</h1>
    <a href="<?= url('/people') ?>" class="btn btn-ghost">← Zpět</a>
</div>

<div class="card">
    <form method="POST" action="<?= url('/people') ?>">
        <div class="form-group required">
            <label for="full_name">Celé jméno <span class="req">*</span></label>
            <input type="text" name="full_name" id="full_name" required maxlength="255" autofocus
                   value="<?= \App\Core\View::e($formData['full_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="short_name">Krátké jméno</label>
            <input type="text" name="short_name" id="short_name" maxlength="100"
                   value="<?= \App\Core\View::e($formData['short_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="note">Poznámka</label>
            <textarea name="note" id="note" rows="4"><?= \App\Core\View::e($formData['note'] ?? '') ?></textarea>
        </div>
        <div class="form-footer">
            <button type="submit" class="btn btn-primary">Vytvořit osobu</button>
            <a href="<?= url('/people') ?>" class="btn btn-ghost">Zrušit</a>
        </div>
    </form>
</div>
