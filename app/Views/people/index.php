<div class="page-header">
    <h1>Osoby</h1>
    <?= permLink(in_array('person.manage', $allowedOps, true), url('/people/create'), '+ Nová osoba', 'btn btn-primary', 'Nemáte oprávnění vytvořit osobu.') ?>
</div>

<?php if (empty($people)): ?>
    <p class="empty-state">Žádné osoby nejsou evidovány.</p>
<?php else: ?>
<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th data-sort="text">Jméno</th>
                <th>Kontakty</th>
                <th>Stav</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($people as $p): ?>
            <tr>
                <td>
                    <?= \App\Core\View::e($p['full_name']) ?>
                    <?php if ($p['short_name']): ?><small>(<?= \App\Core\View::e($p['short_name']) ?>)</small><?php endif; ?>
                </td>
                <td><?= (int) $p['contact_count'] ?></td>
                <td>
                    <span class="perm-badge <?= $p['deactivated_at'] === null ? 'perm-granted' : 'perm-revoked' ?>">
                        <?= $p['deactivated_at'] === null ? 'Aktivní' : 'Deaktivovaná' ?>
                    </span>
                </td>
                <td><a href="<?= url('/people/' . (int) $p['id']) ?>" class="btn btn-sm">Detail</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
