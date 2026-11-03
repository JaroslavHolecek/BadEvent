<?php
$__canManage = in_array('person.manage', $allowedOps, true);
$__canManageUsers = in_array('users.manage', $allowedOps, true);
?>

<div class="page-header">
    <div>
        <a href="<?= url('/people') ?>" class="breadcrumb">← Osoby</a>
        <h1><?= \App\Core\View::e($person['full_name']) ?></h1>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-header-title">
            <h2>Základní informace</h2>
            <span class="perm-badge <?= $person['deactivated_at'] === null ? 'perm-granted' : 'perm-revoked' ?>">
                <?= $person['deactivated_at'] === null ? 'Aktivní' : 'Deaktivovaná' ?>
            </span>
        </div>
        <?= permLink($__canManage, url('/people/' . (int) $person['id'] . '/edit'), 'Upravit', 'btn btn-sm btn-ghost', 'Nemáte oprávnění upravit osobu.') ?>
    </div>
    <dl class="detail-grid">
        <dt>Celé jméno</dt>
        <dd><?= \App\Core\View::e($person['full_name']) ?></dd>

        <dt>Krátké jméno</dt>
        <dd><?= $person['short_name'] ? \App\Core\View::e($person['short_name']) : '—' ?></dd>

        <?php if ($person['note']): ?>
        <dt>Poznámka</dt>
        <dd><?= nl2br(\App\Core\View::e($person['note'])) ?></dd>
        <?php endif; ?>
    </dl>

    <div class="form-section">
        <h3>Uživatelský účet</h3>
        <?php if ($linkedUser !== null): ?>
        <p>
            Přihlašovací účet: <strong><?= \App\Core\View::e($linkedUser['email'] ?? '—') ?></strong>
            <?= permLink($__canManageUsers, url('/admin/users/' . (int) $linkedUser['id'] . '/edit'), 'Upravit', 'btn btn-sm btn-ghost', 'Nemáte oprávnění spravovat uživatele.') ?>
        </p>
        <?php else: ?>
        <form method="POST" action="<?= url('/people/' . (int) $person['id'] . '/create-user') ?>" class="form-row">
            <div class="form-group required">
                <label for="new_user_email">E-mail pro přihlášení <span class="req">*</span></label>
                <input type="email" name="email" id="new_user_email" required maxlength="255" <?= $__canManageUsers ? '' : 'disabled' ?>>
            </div>
            <div class="form-group" style="align-self:flex-end">
                <button type="submit" class="btn btn-sm btn-primary" <?= $__canManageUsers ? '' : 'disabled title="Nemáte oprávnění spravovat uživatele."' ?>>Vytvořit uživatele</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="form-footer">
        <?php if ($person['deactivated_at'] === null): ?>
        <form method="POST" action="<?= url('/people/' . (int) $person['id'] . '/deactivate') ?>">
            <button type="submit" class="btn btn-sm btn-danger" <?= $__canManage ? '' : 'disabled title="Nemáte oprávnění upravit osobu."' ?>>Deaktivovat</button>
        </form>
        <?php else: ?>
        <form method="POST" action="<?= url('/people/' . (int) $person['id'] . '/activate') ?>">
            <button type="submit" class="btn btn-sm btn-success" <?= $__canManage ? '' : 'disabled title="Nemáte oprávnění upravit osobu."' ?>>Aktivovat</button>
        </form>
        <?php endif; ?>
        <form method="POST" action="<?= url('/people/' . (int) $person['id'] . '/delete') ?>"
              class="confirm-delete-form" data-confirm-message="Opravdu smazat tuto osobu? Kontakty budou odstraněny.">
            <button type="submit" class="btn btn-sm btn-danger" <?= $__canManage ? '' : 'disabled title="Nemáte oprávnění upravit osobu."' ?>>Smazat</button>
        </form>
    </div>
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
                    <form method="POST" action="<?= url('/people/' . (int) $person['id'] . '/contacts/' . (int) $c['id'] . '/deactivate') ?>" style="display:inline-block">
                        <button type="submit" class="btn btn-sm btn-danger" <?= $__canManage ? '' : 'disabled title="Nemáte oprávnění upravit osobu."' ?>>Deaktivovat</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" action="<?= url('/people/' . (int) $person['id'] . '/contacts/' . (int) $c['id'] . '/activate') ?>" style="display:inline-block">
                        <button type="submit" class="btn btn-sm btn-success" <?= $__canManage ? '' : 'disabled title="Nemáte oprávnění upravit osobu."' ?>>Aktivovat</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($__canManage): ?>
                    <details class="inline-edit">
                        <summary class="btn btn-sm btn-ghost">Upravit</summary>
                        <form method="POST" action="<?= url('/people/' . (int) $person['id'] . '/contacts/' . (int) $c['id'] . '/edit') ?>">
                            <select name="contact_type_id" required>
                                <?php foreach ($contactTypes as $ct): ?>
                                <option value="<?= (int) $ct['id'] ?>" <?= (int) $ct['id'] === (int) $c['contact_type_id'] ? 'selected' : '' ?>><?= \App\Core\View::e($ct['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="value" required maxlength="255" value="<?= \App\Core\View::e($c['value']) ?>">
                            <input type="text" name="note" placeholder="Poznámka" value="<?= \App\Core\View::e($c['note'] ?? '') ?>">
                            <button type="submit" class="btn btn-sm btn-warning">Uložit</button>
                        </form>
                    </details>
                    <?php endif; ?>
                    <form method="POST" action="<?= url('/people/' . (int) $person['id'] . '/contacts/' . (int) $c['id'] . '/delete') ?>"
                          class="confirm-delete-form" style="display:inline-block" data-confirm-message="Opravdu smazat tento kontakt?">
                        <button type="submit" class="btn btn-sm btn-danger" <?= $__canManage ? '' : 'disabled title="Nemáte oprávnění upravit osobu."' ?>>Smazat</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3>Přidat kontakt</h3>
    <form method="POST" action="<?= url('/people/' . (int) $person['id'] . '/contacts') ?>">
        <div class="form-row">
            <div class="form-group required">
                <label for="contact_type_id">Typ <span class="req">*</span></label>
                <select name="contact_type_id" id="contact_type_id" required <?= $__canManage ? '' : 'disabled' ?>>
                    <option value="">-- vyberte typ --</option>
                    <?php foreach ($contactTypes as $ct): ?>
                    <option value="<?= (int) $ct['id'] ?>"><?= \App\Core\View::e($ct['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group required">
                <label for="value">Hodnota <span class="req">*</span></label>
                <input type="text" name="value" id="value" required maxlength="255" <?= $__canManage ? '' : 'disabled' ?>>
            </div>
            <div class="form-group">
                <label for="contact_note">Poznámka</label>
                <input type="text" name="note" id="contact_note" <?= $__canManage ? '' : 'disabled' ?>>
            </div>
        </div>
        <div class="form-footer">
            <button type="submit" class="btn btn-primary btn-sm" <?= $__canManage ? '' : 'disabled title="Nemáte oprávnění upravit osobu."' ?>>Přidat kontakt</button>
        </div>
    </form>
</div>
