<?php
$monthNames = ['', 'Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen',
               'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'];
$prevMonth = $month === 1 ? ['year' => $year - 1, 'month' => 12] : ['year' => $year, 'month' => $month - 1];
$nextMonth = $month === 12 ? ['year' => $year + 1, 'month' => 1] : ['year' => $year, 'month' => $month + 1];
$today = date('Y-m-d');
?>
<div class="page-header">
    <h1>Kódování budovy a poznámka ke dni</h1>
    <a href="<?= url('/calendar/places') ?>" class="btn btn-ghost">← Kalendář místností</a>
</div>

<div class="calendar-nav">
    <?php if ($canPrev): ?>
    <a href="<?= url('/admin/building-code-duty?year=' . $prevMonth['year'] . '&month=' . $prevMonth['month']) ?>" class="btn btn-ghost">← <?= $monthNames[$prevMonth['month']] ?></a>
    <?php else: ?>
    <span class="btn btn-ghost btn-disabled">← <?= $monthNames[$prevMonth['month']] ?></span>
    <?php endif; ?>
    <h2 class="calendar-month"><?= $monthNames[$month] ?> <?= $year ?></h2>
    <?php if ($canNext): ?>
    <a href="<?= url('/admin/building-code-duty?year=' . $nextMonth['year'] . '&month=' . $nextMonth['month']) ?>" class="btn btn-ghost"><?= $monthNames[$nextMonth['month']] ?> →</a>
    <?php else: ?>
    <span class="btn btn-ghost btn-disabled"><?= $monthNames[$nextMonth['month']] ?> →</span>
    <?php endif; ?>
</div>

<p class="hint">Nastavit lze jen v rozsahu dva měsíce zpět až dva měsíce dopředu.</p>

<div class="card">
    <form method="POST" action="<?= url('/admin/building-code-duty/save') ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <table class="table">
            <thead>
                <tr><th>Datum</th><th>Hlavní osoba</th><th>Náhradní osoba</th><th>Poznámka ke dni</th></tr>
            </thead>
            <tbody>
                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                    $date    = sprintf('%04d-%02d-%02d', $year, $month, $d);
                    $row     = $byDate[$date] ?? null;
                    $isToday = $date === $today;
                    $primaryId    = $row ? (int) $row['primary_user_id'] : 0;
                    $substituteId = $row ? (int) $row['substitute_user_id'] : 0;
                ?>
                <tr<?= $isToday ? ' class="today-row"' : '' ?>>
                    <td><?= (int) $d ?>. <?= $monthNames[$month] ?><?= $isToday ? ' (dnes)' : '' ?></td>
                    <td>
                        <select name="primary_user_id[<?= $date ?>]">
                            <option value="0">— nikdo —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= $primaryId === (int) $u['id'] ? 'selected' : '' ?>>
                                <?= \App\Core\View::e($u['full_name'] ?? $u['short_name'] ?? ('Uživatel #' . $u['id'])) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="substitute_user_id[<?= $date ?>]">
                            <option value="0">— nikdo —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= $substituteId === (int) $u['id'] ? 'selected' : '' ?>>
                                <?= \App\Core\View::e($u['full_name'] ?? $u['short_name'] ?? ('Uživatel #' . $u['id'])) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="note[<?= $date ?>]" value="<?= \App\Core\View::e($row['note'] ?? '') ?>" placeholder="(bez poznámky)">
                    </td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        <div class="form-footer">
            <button type="submit" class="btn btn-primary">Uložit</button>
        </div>
    </form>
</div>
