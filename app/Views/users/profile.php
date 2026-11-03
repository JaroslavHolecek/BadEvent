<div class="page-header">
    <h1>Můj profil</h1>
</div>

<div class="card">
    <div class="card-header"><h2>Kontakty</h2></div>
    <?php if (empty($contacts)): ?>
        <p class="empty-state">Žádné kontakty.</p>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr><th>Typ</th><th>Hodnota</th><th>Poznámka</th><th>Stav</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($contacts as $c): ?>
            <tr>
                <td>
                    <?= \App\Core\View::e($c['contact_type_name']) ?>
                    <?php if ($c['contact_type_abbreviation']): ?><small>(<?= \App\Core\View::e($c['contact_type_abbreviation']) ?>)</small><?php endif; ?>
                </td>
                <td><?= \App\Core\View::e($c['value']) ?></td>
                <td><?= \App\Core\View::e($c['note'] ?? '—') ?></td>
                <td>
                    <span class="perm-badge <?= $c['deactivated_at'] === null ? 'perm-granted' : 'perm-revoked' ?>">
                        <?= $c['deactivated_at'] === null ? 'Aktivní' : 'Deaktivovaný' ?>
                    </span>
                </td>
                <td>
                    <?php if ($c['deactivated_at'] === null): ?>
                    <form method="POST" action="<?= url('/profile/contacts/' . (int) $c['id'] . '/deactivate') ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Deaktivovat</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" action="<?= url('/profile/contacts/' . (int) $c['id'] . '/activate') ?>">
                        <button type="submit" class="btn btn-sm btn-success">Aktivovat</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3>Přidat kontakt</h3>
    <form method="POST" action="<?= url('/profile/contacts') ?>">
        <div class="form-row">
            <div class="form-group required">
                <label for="contact_type_id">Typ <span class="req">*</span></label>
                <select name="contact_type_id" id="contact_type_id" required>
                    <option value="">-- vyberte typ --</option>
                    <?php foreach ($contactTypes as $ct): ?>
                    <option value="<?= (int) $ct['id'] ?>"><?= \App\Core\View::e($ct['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group required">
                <label for="value">Hodnota <span class="req">*</span></label>
                <input type="text" name="value" id="value" required maxlength="255">
            </div>
            <div class="form-group">
                <label for="contact_note">Poznámka</label>
                <input type="text" name="note" id="contact_note">
            </div>
        </div>
        <div class="form-footer">
            <button type="submit" class="btn btn-primary btn-sm">Přidat kontakt</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header"><h2>Přihlašovací údaje</h2></div>

    <h3>Email</h3>
    <form method="POST" action="<?= url('/profile/email') ?>">
        <div class="form-row">
            <div class="form-group required">
                <label for="email">Nový email <span class="req">*</span></label>
                <input type="email" name="email" id="email" required maxlength="255"
                       value="<?= \App\Core\View::e($profile['email'] ?? '') ?>">
            </div>
            <?php if (!empty($profile['has_password'])): ?>
            <div class="form-group required">
                <label for="email_current_password">Současné heslo <span class="req">*</span></label>
                <input type="password" name="current_password" id="email_current_password" required>
            </div>
            <?php endif; ?>
        </div>
        <div class="form-footer">
            <button type="submit" class="btn btn-primary btn-sm">Změnit email</button>
        </div>
    </form>

    <h3>Heslo</h3>
    <form method="POST" action="<?= url('/profile/password') ?>">
        <div class="form-row">
            <?php if (!empty($profile['has_password'])): ?>
            <div class="form-group required">
                <label for="current_password">Současné heslo <span class="req">*</span></label>
                <input type="password" name="current_password" id="current_password" required>
            </div>
            <?php else: ?>
            <p class="hint">Účet zatím nemá nastavené heslo (přihlašujete se odkazem).</p>
            <?php endif; ?>
            <div class="form-group required">
                <label for="new_password">Nové heslo <span class="req">*</span></label>
                <input type="password" name="new_password" id="new_password" required>
            </div>
            <div class="form-group required">
                <label for="new_password_confirm">Nové heslo znovu <span class="req">*</span></label>
                <input type="password" name="new_password_confirm" id="new_password_confirm" required>
            </div>
        </div>
        <div class="form-footer">
            <button type="submit" class="btn btn-primary btn-sm"><?= !empty($profile['has_password']) ? 'Změnit heslo' : 'Nastavit heslo' ?></button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header"><h2>Moje role</h2></div>
    <?php if (empty($roles)): ?>
        <p class="empty-state">Nemáte přiřazenou žádnou roli.</p>
    <?php else: ?>
    <ul class="role-list">
        <?php foreach ($roles as $r): ?>
        <li class="role-link<?= (int) $r['id'] === (int) ($activeRole['id'] ?? 0) ? ' active' : '' ?>">
            <?= \App\Core\View::e($r['name']) ?>
            <?php if ((int) $r['id'] === (int) ($activeRole['id'] ?? 0)): ?>
                <small>(aktuálně aktivní)</small>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
