<div class="page-header">
    <h1><?= \App\Core\View::e($title) ?></h1>
    <a href="<?= url('/actions/' . (int) $action['id']) ?>" class="btn btn-ghost">← Zpět na detail</a>
</div>

<?php if ($action['status_code'] === 'closed'): ?>
<div class="alert alert-warning">Pozor, zasahujete do uzavřené akce.</div>
<?php endif; ?>

<div class="card">
    <?php if (!empty($visitorTypes) && !empty($visitorCategories)): ?>
    <form method="POST" action="<?= url('/actions/' . (int) $action['id'] . '/edit-attendance') ?>" id="attendance-form">
        <div class="form-section">
            <h2>Přidat docházku</h2>
            <p class="hint">Zadání stejného typu a kategorie znovu přepíše dříve uložený počet.</p>
            <div class="form-row">
                <div class="form-group">
                    <label for="attendance_visitor_type_id">Typ návštěvníka</label>
                    <select name="attendance_visitor_type_id" id="attendance_visitor_type_id">
                        <option value="">-- vyberte --</option>
                        <?php foreach ($visitorTypes as $vt): ?>
                        <option value="<?= (int) $vt['id'] ?>"><?= \App\Core\View::e($vt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="attendance_visitor_category_id">Kategorie</label>
                    <select name="attendance_visitor_category_id" id="attendance_visitor_category_id">
                        <option value="">-- vyberte --</option>
                        <?php foreach ($visitorCategories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= \App\Core\View::e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="attendance_visitor_count">Počet</label>
                    <input type="number" name="attendance_visitor_count" id="attendance_visitor_count" min="0">
                </div>
                <div class="form-group">
                    <label for="attendance_note">Poznámka</label>
                    <input type="text" name="attendance_note" id="attendance_note">
                </div>
            </div>
            <div class="form-footer">
                <button type="submit" class="btn btn-primary">Přidat další docházku</button>
                <a href="<?= url('/actions/' . (int) $action['id']) ?>" class="btn btn-ghost">Zavřít úpravu docházky</a>
            </div>
        </div>
    </form>
    <?php else: ?>
        <p class="empty-state">Chybí definované typy nebo kategorie návštěvníků — nejprve je založte ve slovnících.</p>
    <?php endif; ?>

    <div class="form-section">
        <h2>Zaznamenaná docházka</h2>
        <?php if (!empty($attendance)): ?>
        <table class="table">
            <thead>
                <tr><th>Typ návštěvníka</th><th>Kategorie</th><th>Počet</th><th>Poznámka</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($attendance as $a): ?>
                <tr>
                    <td><?= \App\Core\View::e($a['visitor_type_name']) ?></td>
                    <td><?= \App\Core\View::e($a['visitor_category_name']) ?></td>
                    <td><?= (int) $a['visitor_count'] ?></td>
                    <td><?= \App\Core\View::e($a['note'] ?? '—') ?></td>
                    <td>
                        <form method="POST" action="<?= url('/actions/' . (int) $action['id'] . '/attendance/' . (int) $a['id'] . '/remove') ?>" style="display:inline-block">
                            <button type="submit" class="btn btn-sm btn-ghost" title="Odebrat">&times;</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty-state">Žádná zaznamenaná docházka.</p>
        <?php endif; ?>
    </div>
</div>
