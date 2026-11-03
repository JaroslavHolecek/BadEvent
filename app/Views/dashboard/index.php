<div class="page-header">
    <h1>Můj přehled</h1>
</div>

<p class="duty-plain">
    Dnes <?= \App\Core\View::e(date('j. n. Y')) ?>
    <?= match ($myDutyRole) {
        'primary'    => 'kóduješ.',
        'substitute' => 'zastupuješ při kódování.',
        default      => 'NEkóduješ.',
    } ?>
</p>
<?php if (!empty($dayNote)): ?>
<p class="duty-plain duty-note"><?= nl2br(\App\Core\View::e($dayNote)) ?></p>
<?php endif; ?>

<?php if (!empty($unread)): ?>
<section class="card">
    <div class="card-header">
        <h2>Upozornění <span class="badge"><?= count($unread) ?></span></h2>
        <form method="POST" action="<?= url('/notifications/read-all') ?>">
            <button type="submit" class="btn btn-sm btn-ghost">Označit vše jako přečtené</button>
        </form>
    </div>
    <ul class="notification-list">
        <?php foreach ($unread as $n): ?>
        <li class="notification-item" data-id="<?= (int) $n['id'] ?>">
            <span class="notif-text"><?= \App\Core\View::e($n['text']) ?></span>
            <span class="notif-time"><?= \App\Core\View::e(date('j. n. H:i', strtotime($n['created_at']))) ?></span>
            <?php if ($n['related_action_id']): ?>
                <a href="<?= url('/actions/' . (int) $n['related_action_id']) ?>" class="btn btn-sm">Zobrazit akci</a>
            <?php endif; ?>
            <button class="btn btn-sm btn-ghost mark-read-btn" data-id="<?= (int) $n['id'] ?>">✓</button>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <h2>Moje akce, které vyžadují pozornost </h2>
        <?= permLink(in_array('action.create', $allowedOps, true), url('/actions/create'), '+ Nová akce', 'btn btn-primary btn-sm', 'Nemáte oprávnění vytvořit akci.') ?>
    </div>

    <?php if (empty($myActions)): ?>
        <p class="empty-state">Žádné akce přiřazeny.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th data-sort="text" class="overdue-col">Název</th>
                    <th data-sort="date">Datum</th>
                    <th>Stav</th>
                    <th>Role</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($myActions as $a): ?>
                <tr>
                    <td class="overdue-col">
                        <?php if ($a['marker'] !== null): ?>
                            <?php $markerTitle = match ($a['marker']) {
                                'draft'     => 'Akce je Rozjednaná, ale je již po termínu',
                                'draft_soon' => 'Akce se již přiblížila a není potvrzená',
                                'confirmed' => 'Akce již skončila, ale není ve stavu Proběhlá',
                                'completed' => 'Akce čeká na uzavření',
                            }; ?>
                            <span class="overdue-dot overdue-dot-<?= \App\Core\View::e(str_replace('_', '-', $a['marker'])) ?>" role="img" title="<?= \App\Core\View::e($markerTitle) ?>" aria-label="<?= \App\Core\View::e($markerTitle) ?>"></span>
                        <?php endif; ?>
                        <?php if (!empty($a['queue_first'])): ?>
                            <span class="queue-dot" role="img" title="Akce má vyžádanou místnost první ve frontě" aria-label="Akce má vyžádanou místnost první ve frontě"></span>
                        <?php endif; ?>
                        <?= \App\Core\View::e($a['name']) ?>
                    </td>
                    <td data-sort-value="<?= \App\Core\View::e((string) ($a['public_start_at'] ?? '')) ?>">
                        <?= $a['public_start_at'] ? \App\Core\View::e(date('j. n. Y H:i', strtotime($a['public_start_at']))) : '—' ?>
                        –
                        <?= $a['public_end_at'] ? \App\Core\View::e(date('j. n. Y H:i', strtotime($a['public_end_at']))) : '—' ?>
                    </td>
                    <td><span class="status-badge status-<?= \App\Core\View::e($a['status_code']) ?>"><?= \App\Core\View::e($a['status_name']) ?></span></td>
                    <td><?= match ($a['user_role']) { 'organizer' => 'Organizátor', 'substitute' => 'Zástup', 'staff' => 'Pomocník', default => '—' } ?></td>
                    <td><a href="<?= url('/actions/' . (int) $a['id']) ?>" class="btn btn-sm">Detail</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<script>
document.querySelectorAll('.mark-read-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.id;
        await fetch('<?= url('/notifications/') ?>' + id + '/read', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}});
        btn.closest('.notification-item').remove();
        const badge = document.querySelector('.badge');
        if (badge) {
            const n = parseInt(badge.textContent) - 1;
            if (n <= 0) { badge.closest('.card')?.remove(); }
            else { badge.textContent = n; }
        }
    });
});
</script>
