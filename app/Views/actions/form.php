<?php
/**
 * Shared create/edit form for actions, distinguished by $isEdit. Field values are always read
 * from $formData (built by the controller — from $action for edit, from duplicate source or
 * empty for create), so the markup below never branches on $isEdit just to pick a value source.
 */
if ($isEdit) {
    // Sorts a list of ids ascending and joins them, matching how main.js's checkChanges()
    // serializes the currently-checked values of a checkbox group ("name[]" fields) for comparison.
    $__sortedIds = function (array $ids): string {
        sort($ids);
        return implode(',', $ids);
    };

    $__original = [
        'name'            => $formData['name'],
        'event_id'        => (string) $action['event_id'],
        'staff_start_at'  => $formData['staff_start_at'],
        'staff_end_at'    => $formData['staff_end_at'],
        'public_start_at' => $formData['public_start_at'],
        'public_end_at'   => $formData['public_end_at'],
        'capacity'        => $formData['capacity'],
        'note'            => $formData['note'],
        'photograph'      => $formData['photograph'] ? '1' : '0',
        'photograph_note' => $formData['photograph_note'],
        'not_main_organizer' => $formData['not_main_organizer'] ? '1' : '0',
        'action_type_id[]'       => $__sortedIds($assignedActionTypes),
        'action_topic_id[]'      => $__sortedIds($assignedActionTopics),
        'action_format_id[]'     => $__sortedIds($assignedActionFormats),
        'visitor_category_id[]'  => $__sortedIds($assignedVisitorCategories),
        'organizer_id[]'         => $__sortedIds($assignedOrganizerIds),
        'substitute_id[]'        => $__sortedIds($assignedSubstituteIds),
        'staff_id[]'             => $__sortedIds($assignedStaffPersonIds),
    ];
    foreach ($places as $__p) {
        $__original['place_state[' . (int) $__p['id'] . ']'] = $roomStates[(int) $__p['id']]['state'];
    }
}

$__formAction = $isEdit ? url('/actions/' . (int) $action['id']) : url('/actions');
$__backHref   = $isEdit ? url('/actions/' . (int) $action['id']) : url('/actions');
?>
<div class="page-header">
    <h1><?= \App\Core\View::e($isEdit ? $title : 'Nová akce') ?></h1>
    <a href="<?= $__backHref ?>" class="btn btn-ghost"><?= $isEdit ? '← Zpět na detail' : '← Zpět' ?></a>
</div>

<?php if (!$isEdit && !empty($duplicatedFrom)): ?>
<div class="alert alert-warning">
    Formulář byl předvyplněn podle akce „<?= \App\Core\View::e($duplicatedFrom) ?>“. Nic zatím nebylo uloženo —
    nová samostatná akce vznikne až po stisknutí „Vytvořit akci“.
</div>
<?php endif; ?>

<?php if ($isEdit && $action['status_code'] === 'closed'): ?>
<div class="alert alert-warning">Pozor, zasahujete do uzavřené akce.</div>
<?php endif; ?>

<div class="card">
    <form method="POST" action="<?= $__formAction ?>" id="action-form" data-track
          <?php if ($isEdit): ?>data-original='<?= htmlspecialchars(json_encode($__original), ENT_QUOTES, 'UTF-8') ?>'<?php endif; ?>>

        <div class="form-section">
            <h2>Událost</h2>
            <?php if ($isEdit): ?>
            <div class="form-group required">
                <label for="event_id">Událost <span class="req">*</span></label>
                <select name="event_id" id="event_id" required>
                    <?php foreach ($events as $e): ?>
                    <option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === (int) $action['event_id'] ? 'selected' : '' ?>>
                        <?= \App\Core\View::e($e['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">Pokud přesunete akci do jiné události a v původní nezůstane žádná akce, bude původní událost odstraněna.</p>
            </div>
            <?php else: ?>
            <div class="form-group">
                <label for="event_id">Vybrat existující událost</label>
                <select name="event_id" id="event_id">
                    <option value="0" <?= empty($preselectedEventId) ? 'selected' : '' ?>>— Nová událost —</option>
                    <?php foreach ($events as $e): ?>
                        <option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === (int) $preselectedEventId ? 'selected' : '' ?>><?= \App\Core\View::e($e['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="new-event-group">
                <label for="new_event_name">Název nové události</label>
                <input type="text" name="new_event_name" id="new_event_name" maxlength="255"
                       placeholder="Název události (pokud nevybíráte existující)">
            </div>
            <?php endif; ?>
        </div>

        <div class="form-section">
            <h2>Základní údaje</h2>
            <div class="form-group required">
                <label for="name">Název akce <span class="req">*</span></label>
                <input type="text" name="name" id="name" required maxlength="255"
                       value="<?= \App\Core\View::e($formData['name'] ?? '') ?>">
            </div>

            <div class="form-row">
                <div class="form-group required">
                    <label for="staff_start_at">Začátek (pro personál) <span class="req">*</span></label>
                    <input type="datetime-local" name="staff_start_at" id="staff_start_at"
                           value="<?= \App\Core\View::e($formData['staff_start_at'] ?? '') ?>">
                </div>
                <div class="form-group required">
                    <label for="staff_end_at">Konec (pro personál) <span class="req">*</span></label>
                    <input type="datetime-local" name="staff_end_at" id="staff_end_at"
                           value="<?= \App\Core\View::e($formData['staff_end_at'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group required">
                    <label for="public_start_at">Zahájení (veřejné) <span class="req">*</span></label>
                    <input type="datetime-local" name="public_start_at" id="public_start_at"
                           value="<?= \App\Core\View::e($formData['public_start_at'] ?? '') ?>">
                </div>
                <div class="form-group required">
                    <label for="public_end_at">Ukončení (veřejné) <span class="req">*</span></label>
                    <input type="datetime-local" name="public_end_at" id="public_end_at"
                           value="<?= \App\Core\View::e($formData['public_end_at'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="capacity">Kapacita účastníků</label>
                <input type="number" name="capacity" id="capacity" min="0"
                       value="<?= \App\Core\View::e($formData['capacity'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="photograph" value="1" <?= !empty($formData['photograph']) ? 'checked' : '' ?>>
                    Má být u akce přítomen mediální tým
                </label>
            </div>
            <div class="form-group">
                <label for="photograph_note">Poznámka pro mediální tým</label>
                <textarea name="photograph_note" id="photograph_note" rows="2"><?= \App\Core\View::e($formData['photograph_note'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="not_main_organizer" value="1" <?= !empty($formData['not_main_organizer']) ? 'checked' : '' ?>>
                    Nejsme hlavní pořadatel
                </label>
            </div>

            <?php $__preselectedActionTypes = array_map('intval', $formData['action_type_id'] ?? []); ?>
            <?php if (!empty($actionTypes)): ?>
            <div class="form-group required">
                <label>Typ akce <span class="req">*</span></label>
                <div class="checkbox-grid">
                    <?php foreach ($actionTypes as $t): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="action_type_id[]" value="<?= (int) $t['id'] ?>"
                               <?= in_array((int) $t['id'], $__preselectedActionTypes, true) ? 'checked' : '' ?>>
                        <?= \App\Core\View::e($t['name']) ?>
                        <?php if ($t['abbreviation']): ?>
                            <small>(<?= \App\Core\View::e($t['abbreviation']) ?>)</small>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php $__preselectedActionTopics = array_map('intval', $formData['action_topic_id'] ?? []); ?>
            <?php if (!empty($actionTopics)): ?>
            <div class="form-group">
                <label>Téma akce</label>
                <div class="checkbox-grid">
                    <?php foreach ($actionTopics as $t): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="action_topic_id[]" value="<?= (int) $t['id'] ?>"
                               <?= in_array((int) $t['id'], $__preselectedActionTopics, true) ? 'checked' : '' ?>>
                        <?= \App\Core\View::e($t['name']) ?>
                        <?php if ($t['abbreviation']): ?>
                            <small>(<?= \App\Core\View::e($t['abbreviation']) ?>)</small>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php $__preselectedActionFormats = array_map('intval', $formData['action_format_id'] ?? []); ?>
            <?php if (!empty($actionFormats)): ?>
            <div class="form-group required">
                <label>Provedení akce <span class="req">*</span></label>
                <div class="checkbox-grid">
                    <?php foreach ($actionFormats as $t): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="action_format_id[]" value="<?= (int) $t['id'] ?>"
                               <?= in_array((int) $t['id'], $__preselectedActionFormats, true) ? 'checked' : '' ?>>
                        <?= \App\Core\View::e($t['name']) ?>
                        <?php if ($t['abbreviation']): ?>
                            <small>(<?= \App\Core\View::e($t['abbreviation']) ?>)</small>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php $__preselectedVisitorCategories = array_map('intval', $formData['visitor_category_id'] ?? []); ?>
            <?php if (!empty($visitorCategories)): ?>
            <div class="form-group">
                <label>Kategorie návštěvníků</label>
                <div class="checkbox-grid">
                    <?php foreach ($visitorCategories as $c): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="visitor_category_id[]" value="<?= (int) $c['id'] ?>"
                               <?= in_array((int) $c['id'], $__preselectedVisitorCategories, true) ? 'checked' : '' ?>>
                        <?= \App\Core\View::e($c['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="form-section">
            <h2>Personál</h2>

            <div class="form-group">
                <label>Organizátoři</label>
                <?php if (!empty($allOrganizers)): ?>
                <div class="checkbox-grid">
                    <?php foreach ($allOrganizers as $u): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="organizer_id[]" value="<?= (int) $u['id'] ?>"
                               <?= in_array((int) $u['id'], $assignedOrganizerIds, true) ? 'checked' : '' ?>>
                        <?= \App\Core\View::e($u['full_name'] ?? $u['short_name'] ?? ('Uživatel #' . $u['id'])) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <p class="empty-state">Žádní uživatelé v roli organizátora.</p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Zástup</label>
                <?php if (!empty($allSubstitutes)): ?>
                <div class="checkbox-grid">
                    <?php foreach ($allSubstitutes as $u): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="substitute_id[]" value="<?= (int) $u['id'] ?>"
                               <?= in_array((int) $u['id'], $assignedSubstituteIds, true) ? 'checked' : '' ?>>
                        <?= \App\Core\View::e($u['full_name'] ?? $u['short_name'] ?? ('Uživatel #' . $u['id'])) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <p class="empty-state">Žádní uživatelé v roli zástupu.</p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Pomocníci</label>
                <?php if (!empty($allStaff)): ?>
                <div class="checkbox-grid">
                    <?php foreach ($allStaff as $u): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="staff_id[]" value="<?= (int) $u['person_id'] ?>"
                               <?= in_array((int) $u['person_id'], $assignedStaffPersonIds, true) ? 'checked' : '' ?>>
                        <?= \App\Core\View::e($u['full_name'] ?? $u['short_name'] ?? ('Uživatel #' . $u['id'])) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <p class="empty-state">Žádní uživatelé v roli pomocníka.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-section">
            <h2>Poznámka</h2>
            <div class="form-group">
                <textarea name="note" rows="4" placeholder="Volitelná poznámka…"><?= \App\Core\View::e($formData['note'] ?? '') ?></textarea>
            </div>
        </div>

        <?php if ($isEdit): ?>
        <div id="changed-indicator" class="changed-banner" style="display:none">
            Formulář obsahuje neuložené změny.
        </div>
        <?php endif; ?>

        <div class="form-footer">
            <?php if ($isEdit): ?>
                <button type="submit" class="btn btn-primary" id="submit-btn" disabled>Uložit změny</button>
                <a href="<?= url('/actions/' . (int) $action['id']) ?>" class="btn btn-ghost">Zrušit</a>
                <span class="hint">Neukládá změny v místnostech.</span>
            <?php else: ?>
                <button type="submit" class="btn btn-primary">Vytvořit akci</button>
                <a href="<?= url('/actions') ?>" class="btn btn-ghost">Zrušit</a>
            <?php endif; ?>
        </div>

        <?php if (!empty($places)): ?>
        <div class="form-section" id="action-form-places">
            <h2>Místnosti <span class="req">*</span></h2>
            <p class="hint">Nic = bez požadavku, Vyžádaná = čeká na potvrzení, Zabraná = potvrzené přiřazení místnosti.
                Tlačítko „Zabraná“ místnost rovnou zabere, pokud je volná a není u ní fronta; jinak akci jen zařadí do fronty žadatelů.
                Alespoň jedna místnost musí být Vyžádaná nebo Zabraná.</p>
            <div class="room-state-list">
                <?php foreach ($places as $p): ?>
                <?php $placeId = (int) $p['id']; $rs = $roomStates[$placeId]; ?>
                <div class="room-state-row">
                    <div class="room-state-name">
                        <?= \App\Core\View::e($p['name']) ?>
                        <?php if ($p['abbreviation']): ?><small>(<?= \App\Core\View::e($p['abbreviation']) ?>)</small><?php endif; ?>
                    </div>
                    <div class="place-state-group">
                        <label class="place-state-option">
                            <input type="radio" name="place_state[<?= $placeId ?>]" value="none"
                                   <?= $rs['state'] === 'none' ? 'checked' : '' ?>>
                            <span class="btn btn-sm btn-ghost">Nic</span>
                        </label>
                        <label class="place-state-option">
                            <input type="radio" name="place_state[<?= $placeId ?>]" value="requested"
                                   <?= $rs['state'] === 'requested' ? 'checked' : '' ?>>
                            <span class="btn btn-sm btn-ghost">Vyžádaná</span>
                        </label>
                        <label class="place-state-option">
                            <input type="radio" name="place_state[<?= $placeId ?>]" value="confirmed"
                                   <?= $rs['state'] === 'confirmed' ? 'checked' : '' ?>>
                            <span class="btn btn-sm btn-ghost">Zabraná</span>
                        </label>
                    </div>
                    <?php if ($rs['state'] !== 'none'): ?>
                    <div class="room-state-meta hint">
                        Vyžádáno <?= \App\Core\View::e(date('j. n. Y H:i', strtotime($rs['requested_at']))) ?>,
                        pořadí ve frontě: <?= (int) $rs['queue_position'] ?>.
                    </div>
                    <?php endif; ?>
                    <div class="room-state-note" style="<?= $rs['state'] === 'none' ? 'display:none' : '' ?>">
                        <input type="text" name="place_note[<?= $placeId ?>]" placeholder="Poznámka k místnosti pro tuto akci"
                               value="<?= \App\Core\View::e((string) ($rs['note'] ?? '')) ?>">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="form-footer">
                <?php if ($isEdit): ?>
                    <button type="submit" formaction="<?= url('/actions/' . (int) $action['id'] . '/places') ?>" formnovalidate class="btn btn-sm btn-primary">Uložit místnosti</button>
                    <span class="hint">Ukládá pouze změnu v místnostech.</span>
                <?php else: ?>
                    <button type="submit" disabled class="btn btn-sm btn-primary">Uložit místnosti</button>
                    <span class="hint">Místnosti se uloží společně se všemi dalšími informacemi vytvořením nové akce.</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($isEdit): ?>
    <details class="danger-zone">
        <summary>Nebezpečná zóna</summary>
        <p class="hint">Smazání akce je nevratné.</p>
        <form method="POST" action="<?= url('/actions/' . (int) $action['id'] . '/delete') ?>"
              class="confirm-delete-form" data-confirm-message="Opravdu trvale smazat tuto akci?">
            <button type="submit" class="btn btn-danger" <?= $canDeleteAction ? '' : 'disabled title="Nemáte oprávnění smazat tuto akci."' ?>>Smazat akci</button>
        </form>
    </details>
    <?php endif; ?>
</div>

<script>
<?php if (!$isEdit): ?>
const eventSelect = document.getElementById('event_id');
const newEventGroup = document.getElementById('new-event-group');
const newEventNameInput = document.getElementById('new_event_name');

function toggleNewEvent() {
    newEventGroup.style.display = eventSelect.value === '0' ? 'block' : 'none';
}
eventSelect.addEventListener('change', toggleNewEvent);
toggleNewEvent();

// Vybrat existující událost i nová událost jsou v HTML jen volitelné (jedno, nebo druhé
// musí být vyplněné) — prohlédnutí nejde vynutit atributem required, takže se kontroluje
// zde při odeslání, aby formulář při chybějícím vyplnění nepřišel o rozepsané údaje.
document.getElementById('action-form').addEventListener('submit', function (e) {
    if (eventSelect.value === '0' && !newEventNameInput.value.trim()) {
        e.preventDefault();
        alert('Vyberte existující událost, nebo vyplňte název nové události.');
        showFormProblems([newEventNameInput], 0);
    }
});
<?php endif; ?>

initRoomStateNotes();
initActionFormRequiredFieldsValidation('action-form');

initActionDateDefaults({
    staffBufferMinutes: <?= (int) $actionDefaults['staff_buffer_minutes'] ?>,
    defaultDurationMinutes: <?= (int) $actionDefaults['default_duration_minutes'] ?>,
});
</script>
