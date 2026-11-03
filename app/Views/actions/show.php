<?php
$statusCode = $action['status_code'];
$transitions = [
    'draft'     => [
        ['code' => 'confirmed',  'label' => 'Potvrdit akci',      'op' => 'action.confirm',        'class' => 'btn-success'],
        ['code' => 'not_agreed', 'label' => 'Odvolat (Nedojednat)','op' => 'action.mark_not_agreed','class' => 'btn-warning'],
    ],
    'not_agreed' => [
        ['code' => 'draft', 'label' => 'Vrátit do rozjednané', 'op' => 'action.mark_not_agreed', 'class' => 'btn-ghost'],
    ],
    'confirmed' => [
        ['code' => 'completed', 'label' => 'Označit jako proběhlou', 'op' => 'action.complete', 'class' => 'btn-success'],
        ['code' => 'cancelled', 'label' => 'Zrušit akci',            'op' => 'action.cancel',  'class' => 'btn-danger'],
        ['code' => 'draft',     'label' => 'Vrátit do rozjednané',   'op' => 'action.confirm', 'class' => 'btn-ghost'],
    ],
    'cancelled' => [
        ['code' => 'confirmed', 'label' => 'Vrátit do potvrzené', 'op' => 'action.cancel', 'class' => 'btn-ghost'],
    ],
    'completed' => [
        ['code' => 'closed',    'label' => 'Uzavřít akci',        'op' => 'action.close',    'class' => 'btn-primary'],
        ['code' => 'confirmed', 'label' => 'Vrátit do potvrzené', 'op' => 'action.complete', 'class' => 'btn-ghost'],
    ],
    'closed' => [
        ['code' => 'completed', 'label' => 'Vrátit do proběhlé', 'op' => 'action.close', 'class' => 'btn-ghost'],
    ],
];
$availableTransitions = $transitions[$statusCode] ?? [];
?>

<div class="page-header">
    <div>
        <a href="<?= url('/actions') ?>" class="breadcrumb">← Kalendář akcí</a>
        <h1><?= \App\Core\View::e($action['name']) ?></h1>
    </div>
    <div class="page-actions">
        <?= permLink(in_array('action.create', $allowedOps, true), url('/actions/' . (int) $action['id'] . '/duplicate'), 'Duplikovat', 'btn btn-ghost', 'Nemáte oprávnění vytvořit akci.') ?>
    </div>
</div>

<div class="action-layout">
    <div class="action-main">
        <div class="card">
            <div class="card-header">
                <div class="card-header-title">
                    <h2>Základní informace</h2>
                    <span class="status-badge status-<?= \App\Core\View::e($statusCode) ?>">
                        <?= \App\Core\View::e($action['status_name']) ?>
                    </span>
                </div>
                <?= permLink($canEditAction, url('/actions/' . (int) $action['id'] . '/edit'), 'Upravit', 'btn btn-sm btn-ghost', 'Upravovat akci může jen její organizátor nebo administrátor.') ?>
            </div>
            <?php $__canViewBasic = in_array('action.view_basic', $allowedOps, true); ?>
            <?php if ($__canViewBasic): ?>
            <dl class="detail-grid">
                <dt>Událost</dt>
                <dd><a href="<?= url('/events/' . (int) $action['event_id']) ?>"><?= \App\Core\View::e($action['event_name']) ?></a></dd>

                <dt>Zahájení (veřejné)</dt>
                <dd><?= $action['public_start_at'] ? \App\Core\View::e(date('j. n. Y H:i', strtotime($action['public_start_at']))) : '—' ?></dd>

                <dt>Ukončení (veřejné)</dt>
                <dd><?= $action['public_end_at'] ? \App\Core\View::e(date('j. n. Y H:i', strtotime($action['public_end_at']))) : '—' ?></dd>

                <dt>Začátek (personál)</dt>
                <dd><?= $action['staff_start_at'] ? \App\Core\View::e(date('j. n. Y H:i', strtotime($action['staff_start_at']))) : '—' ?></dd>

                <dt>Konec (personál)</dt>
                <dd><?= $action['staff_end_at'] ? \App\Core\View::e(date('j. n. Y H:i', strtotime($action['staff_end_at']))) : '—' ?></dd>

                <dt>Kapacita účastníků</dt>
                <dd><?= $action['capacity'] !== null ? (int) $action['capacity'] : '—' ?></dd>

                <dt>Mediální tým</dt>
                <dd><?= $action['photograph'] ? 'Ano' : 'Ne' ?></dd>

                <?php if ($action['photograph'] && $action['photograph_note']): ?>
                <dt>Poznámka pro mediální tým</dt>
                <dd><?= nl2br(\App\Core\View::e($action['photograph_note'])) ?></dd>
                <?php endif; ?>

                <dt>Nejsme hlavní pořadatel</dt>
                <dd><?= $action['not_main_organizer'] ? 'Ano' : 'Ne' ?></dd>

                <?php if (!empty($actionTypes)): ?>
                <dt>Typ akce</dt>
                <dd><?= implode(', ', array_map(fn($t) => \App\Core\View::e($t['name']), $actionTypes)) ?></dd>
                <?php endif; ?>

                <?php if (!empty($actionTopics)): ?>
                <dt>Téma akce</dt>
                <dd><?= implode(', ', array_map(fn($t) => \App\Core\View::e($t['name']), $actionTopics)) ?></dd>
                <?php endif; ?>

                <?php if (!empty($actionFormats)): ?>
                <dt>Provedení akce</dt>
                <dd><?= implode(', ', array_map(fn($t) => \App\Core\View::e($t['name']), $actionFormats)) ?></dd>
                <?php endif; ?>

                <?php if (!empty($visitorCategories)): ?>
                <dt>Kategorie návštěvníků</dt>
                <dd><?= implode(', ', array_map(fn($c) => \App\Core\View::e($c['name']), $visitorCategories)) ?></dd>
                <?php endif; ?>

                <?php if ($action['note']): ?>
                <dt>Poznámka</dt>
                <dd><?= nl2br(\App\Core\View::e($action['note'])) ?></dd>
                <?php endif; ?>
            </dl>

            <?php $__requestedPlaces = array_filter($placeDesires, fn($d) => !$d['confirmed']); ?>
            <?php $__securedPlaces   = array_filter($placeDesires, fn($d) => $d['confirmed']); ?>

            <div id="action-places">
            <h3>Zabrané místnosti</h3>
            <?php if (empty($__securedPlaces)): ?>
            <p class="empty-state">Žádná místnost není aktuálně zabraná.</p>
            <?php else: ?>
            <table class="table">
                <thead>
                    <tr><th>Místnost</th><th>Vyžádáno</th><th>Poznámka</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($__securedPlaces as $d): ?>
                    <tr>
                        <td>
                            <?= \App\Core\View::e($d['name']) ?>
                            <?php if ($d['abbreviation']): ?><small>(<?= \App\Core\View::e($d['abbreviation']) ?>)</small><?php endif; ?>
                        </td>
                        <td><?= \App\Core\View::e(date('j. n. Y H:i', strtotime($d['created_at']))) ?></td>
                        <td><?= \App\Core\View::e($d['note'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h3>Vyžádané místnosti</h3>
            <?php if (empty($__requestedPlaces)): ?>
                <p class="empty-state">Žádné požadavky na místnosti.</p>
            <?php else: ?>
            <table class="table">
                <thead>
                    <tr><th>Místnost</th><th>Vyžádáno</th><th>Pořadí ve frontě</th><th>Poznámka</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($__requestedPlaces as $d): ?>
                    <tr>
                        <td>
                            <?= \App\Core\View::e($d['name']) ?>
                            <?php if ($d['abbreviation']): ?><small>(<?= \App\Core\View::e($d['abbreviation']) ?>)</small><?php endif; ?>
                        </td>
                        <td><?= \App\Core\View::e(date('j. n. Y H:i', strtotime($d['created_at']))) ?></td>
                        <td><?= (int) $d['queue_position'] ?>.</td>
                        <td><?= \App\Core\View::e($d['note'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            </div>

            <h3>Organizátoři</h3>
            <?php if (!empty($organizers)): ?>
            <ul>
                <?php foreach ($organizers as $o): ?>
                <li><?= \App\Core\View::e($o['full_name'] ?? 'Uživatel #' . $o['user_id']) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p class="empty-state">Žádní organizátoři.</p>
            <?php endif; ?>

            <h3>Zástup</h3>
            <?php if (!empty($substitutes)): ?>
            <ul>
                <?php foreach ($substitutes as $s): ?>
                <li><?= \App\Core\View::e($s['full_name'] ?? 'Uživatel #' . $s['user_id']) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p class="empty-state">Žádný zástup.</p>
            <?php endif; ?>

            <h3>Pomocníci</h3>
            <?php if (!empty($staff)): ?>
            <ul>
                <?php foreach ($staff as $st): ?>
                <li><?= \App\Core\View::e($st['full_name'] ?? 'Osoba #' . $st['person_id']) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p class="empty-state">Žádní pomocníci.</p>
            <?php endif; ?>

            <?php else: ?>
                <?= permDenied('Nemáte oprávnění zobrazit základní údaje akce.') ?>
            <?php endif; ?>
        </div>

        <div class="card" id="action-attendance">
            <div class="card-header">
                <h2>Zúčastnilo se</h2>
                <?= permLink($canEditAction, url('/actions/' . (int) $action['id'] . '/edit-attendance'), 'Upravit', 'btn btn-sm btn-ghost', 'Upravovat docházku může jen organizátor akce nebo administrátor.') ?>
            </div>
            <?php if ($__canViewBasic): ?>
                <?php if (empty($attendance)): ?>
                    <p class="empty-state">Žádná zaznamenaná docházka.</p>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr><th>Typ návštěvníka</th><th>Kategorie</th><th>Počet</th><th>Poznámka</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance as $a): ?>
                        <tr>
                            <td><?= \App\Core\View::e($a['visitor_type_name']) ?></td>
                            <td><?= \App\Core\View::e($a['visitor_category_name']) ?></td>
                            <td><?= (int) $a['visitor_count'] ?></td>
                            <td><?= \App\Core\View::e($a['note'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            <?php else: ?>
                <?= permDenied('Nemáte oprávnění zobrazit docházku akce.') ?>
            <?php endif; ?>
        </div>

        <div class="card" id="action-economic">
            <div class="card-header">
                <h2>Ekonomické údaje</h2>
                <?= permLink(in_array('action.update_economic', $allowedOps, true), url('/actions/' . (int) $action['id'] . '/edit-economic'), 'Upravit', 'btn btn-sm btn-ghost', 'Nemáte oprávnění upravit ekonomické údaje akce.') ?>
            </div>
            <?php if (in_array('action.view_economic', $allowedOps, true)): ?>
                <h3>Příjmy</h3>
                <?php if (empty($incomes)): ?>
                    <p class="empty-state">Žádné příjmy.</p>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Typ</th>
                            <th>Podtyp</th>
                            <th>Částka</th>
                            <th>Doklad</th>
                            <th>Poznámka</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incomes as $i): ?>
                        <tr>
                            <td><?= \App\Core\View::e($i['type_name']) ?></td>
                            <td><?= \App\Core\View::e($i['subtype_name'] ?? '—') ?></td>
                            <td><?= \App\Core\View::e(number_format((float) $i['amount'], 2, ',', ' ')) ?> <?= \App\Core\View::e($i['currency_symbol'] ?: $i['currency_code']) ?></td>
                            <td><?= \App\Core\View::e($i['reference_number'] ?? '—') ?></td>
                            <td><?= \App\Core\View::e($i['note'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($incomeTotals)): ?>
                    <tfoot>
                        <?php foreach ($incomeTotals as $t): ?>
                        <tr>
                            <td colspan="2"><strong>Celkem <?= \App\Core\View::e($t['code']) ?></strong></td>
                            <td colspan="3"><strong><?= \App\Core\View::e(number_format((float) $t['total'], 2, ',', ' ')) ?> <?= \App\Core\View::e($t['symbol'] ?: $t['code']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tfoot>
                    <?php endif; ?>
                </table>
                <?php endif; ?>

                <h3>Výdaje</h3>
                <?php if (empty($costs)): ?>
                    <p class="empty-state">Žádné výdaje.</p>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Typ</th>
                            <th>Za co</th>
                            <th>Částka</th>
                            <th>Doklad</th>
                            <th>Poznámka</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($costs as $c): ?>
                        <tr>
                            <td><?= \App\Core\View::e($c['type_name']) ?></td>
                            <td><?= \App\Core\View::e($c['object_name'] ?? '—') ?></td>
                            <td><?= \App\Core\View::e(number_format((float) $c['amount'], 2, ',', ' ')) ?> <?= \App\Core\View::e($c['currency_symbol'] ?: $c['currency_code']) ?></td>
                            <td><?= \App\Core\View::e($c['reference_number'] ?? '—') ?></td>
                            <td><?= \App\Core\View::e($c['note'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($costTotals)): ?>
                    <tfoot>
                        <?php foreach ($costTotals as $t): ?>
                        <tr>
                            <td colspan="2"><strong>Celkem <?= \App\Core\View::e($t['code']) ?></strong></td>
                            <td colspan="3"><strong><?= \App\Core\View::e(number_format((float) $t['total'], 2, ',', ' ')) ?> <?= \App\Core\View::e($t['symbol'] ?: $t['code']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tfoot>
                    <?php endif; ?>
                </table>
                <?php endif; ?>
            <?php else: ?>
                <?= permDenied('Nemáte oprávnění zobrazit ekonomické údaje akce.') ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="action-sidebar">
        <?php if (!empty($availableTransitions)): ?>
        <div class="card">
            <h2>Změna stavu</h2>
            <?php foreach ($availableTransitions as $t): ?>
                <?php $warning = $isAdmin ? ($transitionWarnings[$t['code']] ?? null) : null; ?>
                <?php if (in_array($t['op'], $allowedOps, true)): ?>
                <form method="POST" action="<?= url('/actions/' . (int) $action['id'] . '/transition/' . \App\Core\View::e($t['code'])) ?>" class="transition-form"<?= $warning !== null ? ' data-warning="' . \App\Core\View::e($warning) . '"' : '' ?>>
                    <?php if ($warning !== null): ?>
                    <input type="hidden" name="force" value="0">
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Poznámka ke změně</label>
                        <textarea name="note" rows="2" placeholder="Volitelná…"></textarea>
                    </div>
                    <button type="submit" class="btn <?= $t['class'] ?> btn-block"><?= \App\Core\View::e($t['label']) ?></button>
                </form>
                <?php else: ?>
                <div class="transition-form">
                    <button type="button" disabled class="btn <?= $t['class'] ?> btn-block btn-disabled" title="Nemáte oprávnění k této změně stavu.">
                        <?= \App\Core\View::e($t['label']) ?>
                    </button>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Historie stavů</h2>
            <?php if (empty($statusHistory)): ?>
                <p class="empty-state">Žádná historie.</p>
            <?php else: ?>
            <ul class="status-history">
                <?php foreach ($statusHistory as $h): ?>
                <li>
                    <span class="status-badge status-<?= \App\Core\View::e($h['status_code']) ?>"><?= \App\Core\View::e($h['status_name']) ?></span>
                    <span class="history-time"><?= \App\Core\View::e(date('j. n. Y H:i', strtotime($h['created_at']))) ?></span>
                    <?php if ($h['user_name']): ?>
                        <span class="history-user"><?= \App\Core\View::e($h['user_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($h['note']): ?>
                        <span class="history-note"><?= \App\Core\View::e($h['note']) ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
