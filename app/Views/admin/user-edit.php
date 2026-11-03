<div class="page-header">
    <h1><?= \App\Core\View::e($title) ?></h1>
    <a href="<?= url('/admin/roles') ?>" class="btn btn-ghost">← Zpět</a>
</div>

<div class="card">
    <dl class="detail-grid">
        <dt>Jméno</dt>
        <dd><?= \App\Core\View::e($user['full_name'] ?? $user['short_name'] ?? ('Uživatel #' . $user['id'])) ?></dd>

        <dt>Osoba</dt>
        <dd>
            <?php if ($user['person_id']): ?>
            <a href="<?= url('/people/' . (int) $user['person_id']) ?>"><?= \App\Core\View::e($user['full_name'] ?? ('Osoba #' . $user['person_id'])) ?></a>
            <?php else: ?>—<?php endif; ?>
        </dd>

        <dt>Přihlašovací e-mail</dt>
        <dd><?= \App\Core\View::e($user['email'] ?? '—') ?></dd>
    </dl>

    <form method="POST" action="<?= url('/admin/users/' . (int) $user['id']) ?>">
        <div class="form-group">
            <label for="note">Poznámka</label>
            <textarea name="note" id="note" rows="4"><?= \App\Core\View::e($user['note'] ?? '') ?></textarea>
        </div>
        <div class="form-footer">
            <button type="submit" class="btn btn-primary">Uložit změny</button>
            <a href="<?= url('/admin/roles') ?>" class="btn btn-ghost">Zrušit</a>
        </div>
    </form>

    <div class="form-section">
        <h3>Změnit přihlašovací e-mail</h3>
        <p class="hint">Měníte přihlašovací údaj jiného uživatele.</p>
        <form method="POST" action="<?= url('/admin/users/' . (int) $user['id'] . '/change-email') ?>"
              class="confirm-edit-form" data-confirm-message="Měníte přihlašovací e-mail jiného uživatele — zasahujete do jeho přihlašování.">
            <div class="form-group">
                <label for="email">Nový e-mail</label>
                <input type="email" name="email" id="email" required maxlength="255" value="<?= \App\Core\View::e($user['email'] ?? '') ?>">
            </div>
            <div class="form-footer">
                <button type="submit" class="btn btn-warning">Změnit e-mail</button>
            </div>
        </form>
    </div>
</div>
