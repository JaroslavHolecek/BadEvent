<div class="page-header">
    <h1><?= \App\Core\View::e($title) ?></h1>
</div>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="card">
            <div class="card-header"><h2>Role</h2></div>
            <ul class="role-list">
                <?php foreach ($roles as $r): ?>
                <li>
                    <a href="<?= url('/admin/roles?role_id=' . (int) $r['id']) ?>"
                       class="role-link<?= ($selectedRole !== null && (int) $selectedRole['id'] === (int) $r['id']) ? ' active' : '' ?>">
                        <?= \App\Core\View::e($r['name']) ?>
                        <small><?= \App\Core\View::e($r['code']) ?></small>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card">
            <div class="card-header"><h2>Nový uživatel</h2></div>
            <form method="POST" action="<?= url('/admin/users') ?>">
                <div class="form-group required">
                    <label for="new_user_email">E-mail <span class="req">*</span></label>
                    <input type="email" name="email" id="new_user_email" required maxlength="255">
                </div>
                <div class="form-group required">
                    <label for="new_user_full_name">Celé jméno <span class="req">*</span></label>
                    <input type="text" name="full_name" id="new_user_full_name" required maxlength="255">
                </div>
                <p class="hint">Uživatel se poté přihlásí přes odkaz zaslaný na e-mail.</p>
                <div class="form-footer">
                    <button type="submit" class="btn btn-primary btn-block">Vytvořit uživatele</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header"><h2>Nová role</h2></div>
            <form method="POST" action="<?= url('/admin/roles') ?>">
                <div class="form-group required">
                    <label for="role_name">Název role <span class="req">*</span></label>
                    <input type="text" name="name" id="role_name" required maxlength="255">
                </div>
                <div class="form-group required">
                    <label for="role_code">Kód role <span class="req">*</span></label>
                    <input type="text" name="code" id="role_code" required maxlength="255"
                           pattern="[a-z0-9_]+" placeholder="např. organizer">
                </div>
                <div class="form-footer">
                    <button type="submit" class="btn btn-primary btn-block">Vytvořit roli</button>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-main">
        <?php if ($selectedRole === null): ?>
            <p class="empty-state">Vyberte roli vlevo pro zobrazení a úpravu jejích oprávnění.</p>
        <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h2>Oprávnění role: <?= \App\Core\View::e($selectedRole['name']) ?></h2>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Oprávnění</th>
                        <th>Kód</th>
                        <th>Stav</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($operations as $op): ?>
                    <tr>
                        <td><?= \App\Core\View::e($op['name']) ?></td>
                        <td><code><?= \App\Core\View::e($op['code']) ?></code></td>
                        <td>
                            <span class="perm-badge <?= $op['granted'] ? 'perm-granted' : 'perm-revoked' ?>">
                                <?= $op['granted'] ? 'Uděleno' : 'Neuděleno' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($selectedRole['code'] === 'administrator'): ?>
                                <span class="perm-denied" title="Administrátor má vždy všechna oprávnění.">–</span>
                            <?php elseif ($op['granted']): ?>
                            <form method="POST" action="<?= url('/admin/roles/' . (int) $selectedRole['id'] . '/revoke/' . (int) $op['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Odebrat</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="<?= url('/admin/roles/' . (int) $selectedRole['id'] . '/grant/' . (int) $op['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-success">Přidat</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2>Uživatelé</h2></div>
            <table class="table">
                <thead>
                    <tr>
                        <th data-sort="text">Jméno</th>
                        <th>Role</th>
                        <th>Přidat roli</th>
                        <th>Stav</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <?php $__userRoleIds = array_filter(array_map('intval', explode(',', (string) ($u['role_ids'] ?? '')))); ?>
                    <tr>
                        <td><?= \App\Core\View::e($u['full_name'] ?? $u['short_name'] ?? ('Uživatel #' . $u['id'])) ?></td>
                        <td>
                            <?php if (empty($__userRoleIds)): ?>
                                <span class="empty-state" style="padding:0;">bez role</span>
                            <?php else: ?>
                                <?php foreach ($roles as $r): ?>
                                    <?php if (in_array((int) $r['id'], $__userRoleIds, true)): ?>
                                    <form method="POST" action="<?= url('/admin/users/' . (int) $u['id'] . '/roles/' . (int) $r['id'] . '/remove') ?>" style="display:inline-block;margin:0 .3rem .3rem 0;">
                                        <button type="submit" class="perm-badge perm-granted" style="border:none;cursor:pointer;" title="Odebrat roli">
                                            <?= \App\Core\View::e($r['name']) ?> &times;
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" action="<?= url('/admin/users/' . (int) $u['id'] . '/roles') ?>">
                                <select name="role_id" required>
                                    <option value="">-- vyberte roli --</option>
                                    <?php foreach ($roles as $r): ?>
                                    <option value="<?= (int) $r['id'] ?>"><?= \App\Core\View::e($r['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-success">Přidat</button>
                            </form>
                        </td>
                        <td>
                            <span class="perm-badge <?= $u['deactivated_at'] === null ? 'perm-granted' : 'perm-revoked' ?>">
                                <?= $u['deactivated_at'] === null ? 'Aktivní' : 'Deaktivovaný' ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= url('/admin/users/' . (int) $u['id'] . '/edit') ?>" class="btn btn-sm btn-ghost">Upravit</a>
                            <?php if ($u['deactivated_at'] === null): ?>
                            <form method="POST" action="<?= url('/admin/users/' . (int) $u['id'] . '/deactivate') ?>" style="display:inline-block">
                                <button type="submit" class="btn btn-sm btn-danger">Deaktivovat</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="<?= url('/admin/users/' . (int) $u['id'] . '/activate') ?>" style="display:inline-block">
                                <button type="submit" class="btn btn-sm btn-success">Aktivovat</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" action="<?= url('/admin/users/' . (int) $u['id'] . '/delete') ?>"
                                  class="confirm-delete-form" data-confirm-message="Opravdu smazat tohoto uživatele?"
                                  <?php if (!empty($u['person_id'])): ?>
                                  data-extra-checkbox-name="delete_person" data-extra-checkbox-label="Smazat i přiřazenou osobu"
                                  <?php endif; ?>
                                  style="display:inline-block">
                                <?php if (!empty($u['person_id'])): ?>
                                <input type="checkbox" name="delete_person" value="1" style="display:none" aria-hidden="true">
                                <?php endif; ?>
                                <button type="submit" class="btn btn-sm btn-danger">Smazat</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
