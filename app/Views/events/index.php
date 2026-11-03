<div class="page-header">
    <h1><?= \App\Core\View::e($title) ?></h1>
    <?php if ($canViewArchive): ?>
    <a href="<?= url('/events' . ($archive ? '' : '?archive=1')) ?>" class="btn btn-ghost">
        <?= $archive ? 'Aktivní události' : 'Archiv událostí' ?>
    </a>
    <?php endif; ?>
</div>
<p class="hint">Nová událost vznikne automaticky při vytvoření první akce.</p>

<?php if (empty($events)): ?>
    <p class="empty-state">Žádné události.</p>
<?php else: ?>
<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th data-sort="text">Název</th>
                <th>Počet akcí</th>
                <th data-sort="date">Rozsah</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $e): ?>
            <tr>
                <td><?= \App\Core\View::e($e['name']) ?></td>
                <td><?= (int) $e['action_count'] ?></td>
                <td data-sort-value="<?= \App\Core\View::e((string) ($e['first_action_at'] ?? '')) ?>">
                    <?php if ($e['first_action_at']): ?>
                        <?= \App\Core\View::e(date('j. n. Y', strtotime($e['first_action_at']))) ?>
                        <?php if ($e['last_action_at'] && $e['last_action_at'] !== $e['first_action_at']): ?>
                            – <?= \App\Core\View::e(date('j. n. Y', strtotime($e['last_action_at']))) ?>
                        <?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><a href="<?= url('/events/' . (int) $e['id']) ?>" class="btn btn-sm">Detail</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
