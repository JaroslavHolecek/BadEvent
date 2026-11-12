<?php
/**
 * Table body shared by the logs page (admin/logs.php) and its print export (admin/logs-print.php).
 * Expects $rows from AuditLogRepository::listByDateRange().
 */
$__fmtJson = function (?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $decoded = json_decode($json, true);
    return $decoded === null
        ? $json
        : (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
?>
<?php if (empty($rows)): ?>
<tr><td colspan="6" class="log-empty">V zadaném období nejsou žádné záznamy.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $r):
    $who = $r['user_id'] === null
        ? '—'
        : (($r['full_name'] ?: $r['email']) ?: ('Uživatel #' . (int) $r['user_id']));
    $old = $__fmtJson($r['old_data']);
    $new = $__fmtJson($r['new_data']);
?>
<tr>
    <td class="log-time"><?= \App\Core\View::e(date('j. n. Y H:i:s', strtotime((string) $r['created_at']))) ?></td>
    <td><?= \App\Core\View::e($who) ?></td>
    <td><?= \App\Core\View::e($r['entity_type']) ?></td>
    <td class="log-num"><?= (int) $r['entity_id'] ?></td>
    <td><?= \App\Core\View::e($r['operation']) ?></td>
    <td class="log-data">
        <?php if ($old !== ''): ?><div><strong>Před:</strong> <?= \App\Core\View::e($old) ?></div><?php endif; ?>
        <?php if ($new !== ''): ?><div><strong>Po:</strong> <?= \App\Core\View::e($new) ?></div><?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
