'use strict';

/**
 * Auto-fills the staff/public start & end datetime-local pairs on an action form:
 * - the missing start (staff or public) is derived from the other one, offset by
 *   config.staffBufferMinutes (staff arrives that many minutes before the public start),
 * - public_end_at is derived from public_start_at + config.defaultDurationMinutes,
 * - staff_end_at is derived from public_end_at + config.staffBufferMinutes.
 * Only ever fills fields that are currently empty — never overwrites a user's own value.
 *
 * @param {{staffBufferMinutes: number, defaultDurationMinutes: number}} config
 */
function initActionDateDefaults(config) {
    const staffStart  = document.getElementById('staff_start_at');
    const staffEnd    = document.getElementById('staff_end_at');
    const publicStart = document.getElementById('public_start_at');
    const publicEnd   = document.getElementById('public_end_at');
    if (!staffStart || !staffEnd || !publicStart || !publicEnd) return;

    const bufferMs   = (config.staffBufferMinutes ?? 30) * 60000;
    const durationMs = (config.defaultDurationMinutes ?? 120) * 60000;

    function pad(n) { return String(n).padStart(2, '0'); }

    function toInputValue(date) {
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    function fillDefaults() {
        if (publicStart.value && !staffStart.value) {
            staffStart.value = toInputValue(new Date(new Date(publicStart.value).getTime() - bufferMs));
        } else if (staffStart.value && !publicStart.value) {
            publicStart.value = toInputValue(new Date(new Date(staffStart.value).getTime() + bufferMs));
        }

        if (publicStart.value && !publicEnd.value) {
            publicEnd.value = toInputValue(new Date(new Date(publicStart.value).getTime() + durationMs));
        }

        if (publicEnd.value && !staffEnd.value) {
            staffEnd.value = toInputValue(new Date(new Date(publicEnd.value).getTime() + bufferMs));
        }
    }

    [staffStart, publicStart, publicEnd].forEach((el) => el.addEventListener('change', fillDefaults));
}

/**
 * Tracks form field changes against original data stored in data-original attribute.
 * Highlights changed fields, shows a banner, and enables/disables the submit button.
 *
 * @param {string} formId - The id of the form element.
 */
function initFormTracker(formId) {
    const form = document.getElementById(formId);
    if (!form) return;

    const rawOriginal = form.dataset.original;
    if (!rawOriginal) return;

    let original;
    try {
        original = JSON.parse(rawOriginal);
    } catch {
        return;
    }

    const submitBtn    = document.getElementById('submit-btn');
    const changedBanner = document.getElementById('changed-indicator');

    function currentValue(el) {
        // Radio groups (several inputs sharing one non-"[]" name, e.g. "place_state[12]") aren't
        // a single form control either — form.elements[name] returns a RadioNodeList, which has
        // no .type but exposes .value as the checked radio's value (or '' if none checked).
        if (el instanceof RadioNodeList) return el.value;
        if (el.type === 'checkbox') return el.checked ? '1' : '0';
        return el.value;
    }

    // Checkbox groups (name="foo[]") aren't a single form control — form.elements[name] returns
    // a RadioNodeList for them — so they're tracked as a sorted, comma-joined list of the values
    // that are currently checked, compared against the same format captured at page load.
    function currentGroupValue(name) {
        const boxes = form.querySelectorAll(`input[type="checkbox"][name="${name}"]`);
        return Array.from(boxes).filter((b) => b.checked).map((b) => Number(b.value)).sort((a, b) => a - b).join(',');
    }

    function checkChanges() {
        let hasChanges = false;

        for (const [name, origValue] of Object.entries(original)) {
            if (name.endsWith('[]')) {
                const boxes   = form.querySelectorAll(`input[type="checkbox"][name="${name}"]`);
                const changed = currentGroupValue(name) !== String(origValue ?? '');

                const group = boxes[0] ? boxes[0].closest('.form-group') : null;
                if (group) {
                    group.classList.toggle('field-changed', changed);
                }

                if (changed) hasChanges = true;
                continue;
            }

            const el = form.elements[name];
            if (!el) continue;

            const cur     = currentValue(el);
            const changed = String(cur) !== String(origValue ?? '');

            // .closest() only exists on Element, not RadioNodeList — use its first radio instead.
            const singleEl = el instanceof RadioNodeList ? el[0] : el;
            const group    = singleEl ? singleEl.closest('.form-group') : null;
            if (group) {
                group.classList.toggle('field-changed', changed);
            }

            if (changed) hasChanges = true;
        }

        if (submitBtn) submitBtn.disabled = !hasChanges;
        if (changedBanner) changedBanner.style.display = hasChanges ? 'block' : 'none';
    }

    form.addEventListener('input',  checkChanges);
    form.addEventListener('change', checkChanges);
    checkChanges();
}

/**
 * Shows the per-room "poznámka" input only while that room's place_state picker is set to
 * "requested" or "confirmed" — toggled live as the user clicks between the radio options,
 * not just on initial page load.
 */
function initRoomStateNotes() {
    document.querySelectorAll('.room-state-row').forEach((row) => {
        const noteEl = row.querySelector('.room-state-note');
        const radios = row.querySelectorAll('.place-state-group input[type="radio"]');
        if (!noteEl || radios.length === 0) return;

        function sync() {
            const checked = row.querySelector('.place-state-group input[type="radio"]:checked');
            noteEl.style.display = (checked && checked.value !== 'none') ? '' : 'none';
        }

        radios.forEach((r) => r.addEventListener('change', sync));
    });
}

/**
 * Client-side-only required-field guard on the action create/edit form's main save button —
 * never on "Uložit místnosti" (its own formnovalidate button, disabled on create anyway).
 * Blocks submission entirely with preventDefault() so typed values are never lost to a server
 * round-trip. Required: Název, all four datetime fields, at least one Typ akce, at least one
 * Provedení akce, and at least one room in 'requested'/'confirmed' state.
 */
function initActionFormRequiredFieldsValidation(formId) {
    const form = document.getElementById(formId);
    if (!form) return;

    const REQUIRED_DATETIME_FIELDS = {
        staff_start_at: 'Začátek (pro personál)',
        staff_end_at: 'Konec (pro personál)',
        public_start_at: 'Zahájení (veřejné)',
        public_end_at: 'Ukončení (veřejné)',
    };

    function anyChecked(name) {
        return form.querySelectorAll('input[type="checkbox"][name="' + name + '"]:checked').length > 0;
    }

    function anyRoomSelected() {
        return Array.from(form.querySelectorAll('input[type="radio"][name^="place_state["]'))
            .some((r) => r.checked && r.value !== 'none');
    }

    function groupOf(name) {
        const box = form.querySelector('input[name="' + name + '"]');
        return box ? box.closest('.form-group') : null;
    }

    form.addEventListener('submit', function (e) {
        if (e.submitter && e.submitter.hasAttribute('formnovalidate')) return;

        const missing  = [];
        const problems = [];
        if (!form.elements['name'].value.trim()) {
            missing.push('Název akce');
            problems.push(form.elements['name']);
        }

        Object.keys(REQUIRED_DATETIME_FIELDS).forEach(function (field) {
            const el = form.elements[field];
            if (el && !el.value) {
                missing.push(REQUIRED_DATETIME_FIELDS[field]);
                problems.push(el);
            }
        });

        if (!anyChecked('action_type_id[]')) {
            missing.push('Typ akce');
            problems.push(groupOf('action_type_id[]'));
        }
        if (!anyChecked('action_format_id[]')) {
            missing.push('Provedení akce');
            problems.push(groupOf('action_format_id[]'));
        }
        if (!anyRoomSelected()) {
            missing.push('alespoň jedna místnost (Vyžádaná nebo Zabraná)');
            problems.push(document.getElementById('action-form-places'));
        }

        if (missing.length > 0) {
            e.preventDefault();
            alert('Vyplňte prosím povinné údaje: ' + missing.join(', ') + '.');
            // The alert itself was the error message — no need to wait before scrolling.
            showFormProblems(problems, 0);
        }
    });
}

/** Feedback payload rendered by layout/base.php: {config, action?, fields?, targets?, old?}. */
function formFeedbackData() {
    const el = document.getElementById('form-feedback');
    try {
        return JSON.parse((el && el.textContent) || '{}');
    } catch {
        return {};
    }
}

/**
 * Scrolls so that `el` ends up just below the sticky header. Own animation (not
 * scrollIntoView's 'smooth') so it always takes `durationMs` — fast, yet visibly moving —
 * however far the element is.
 */
function scrollToElement(el, durationMs) {
    const header  = document.querySelector('.site-header');
    const offset  = (header ? header.offsetHeight : 0) + 16;
    const startY  = window.scrollY;
    const targetY = Math.max(0, el.getBoundingClientRect().top + startY - offset);

    if (durationMs <= 0 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        window.scrollTo(0, targetY);
        return;
    }

    const easeInOut = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
    const start = performance.now();
    function step(now) {
        const t = Math.min(1, (now - start) / durationMs);
        window.scrollTo(0, startY + (targetY - startY) * easeInOut(t));
        if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
}

/**
 * Tints what needs fixing in --color-danger: a single input/select/textarea itself (cleared as
 * soon as the user edits it), anything else — a checkbox group, a room list, a page section — as
 * a whole block.
 */
function markProblem(el) {
    const isField = el.matches('input:not([type="checkbox"]):not([type="radio"]), select, textarea');
    if (!isField) {
        el.classList.add('section-error');
        return;
    }

    const group = el.closest('.form-group');
    el.classList.add('field-error');
    if (group) group.classList.add('has-error');

    const clear = () => {
        el.classList.remove('field-error');
        if (group) group.classList.remove('has-error');
    };
    el.addEventListener('input', clear, { once: true });
    el.addEventListener('change', clear, { once: true });
}

/**
 * Highlights `elements` and — after `delayMs` (default: config scroll_delay_ms), so an error
 * message shown at the top can be read first — scrolls to the topmost one and focuses it if it's a field.
 */
function showFormProblems(elements, delayMs) {
    const config = formFeedbackData().config || {};
    const list   = Array.from(new Set(elements.filter(Boolean)))
        .sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1));
    if (list.length === 0) return;

    list.forEach(markProblem);

    setTimeout(() => {
        scrollToElement(list[0], config.scroll_duration_ms ?? 450);
        if (list[0].matches('input, select, textarea')) list[0].focus({ preventScroll: true });
    }, delayMs ?? config.scroll_delay_ms ?? 1000);
}

/**
 * Turns PHP's parsed POST body back into field names: {a: '1', b: ['1','2'], c: {12: 'x'}} →
 * a → '1', b[] → ['1','2'], c[12] → 'x'.
 */
function flattenSubmittedValues(values, prefix, out) {
    out = out || new Map();
    Object.entries(values || {}).forEach(([key, value]) => {
        const name = prefix ? prefix + '[' + key + ']' : key;
        if (Array.isArray(value)) {
            out.set(name + '[]', value.map(String));
        } else if (value !== null && typeof value === 'object') {
            flattenSubmittedValues(value, name, out);
        } else {
            out.set(name, value === null ? '' : String(value));
        }
    });
    return out;
}

/** Fires the events a user's own edit would, so dependent UI (subtype lists, room notes, change tracking) follows. */
function fireEdited(el) {
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
}

/**
 * Puts the submitted values back into `form`. Rows of repeated inputs ("name[]") are first added
 * or removed to match what was sent — via the form's own add-row button marked
 * data-add-row-for="name[]" and the row wrapper marked data-form-row. Password, hidden and file
 * inputs are never touched.
 */
function restoreSubmittedValues(form, submitted) {
    const values = flattenSubmittedValues(submitted);
    const byName = (name) => form.querySelectorAll('[name="' + CSS.escape(name) + '"]');

    form.querySelectorAll('[data-add-row-for]').forEach((button) => {
        const name   = button.dataset.addRowFor;
        const wanted = (values.get(name) || []).length;
        let have = byName(name).length;
        while (have < wanted) {
            button.click();
            const now = byName(name).length;
            if (now === have) break;
            have = now;
        }
        Array.from(byName(name)).slice(wanted).forEach((el) => {
            const row = el.closest('[data-form-row]');
            if (row) row.remove();
        });
    });

    const seen = {};
    Array.from(form.elements).forEach((el) => {
        if (!el.name || el.disabled || el.tagName === 'BUTTON'
            || ['hidden', 'password', 'file', 'submit', 'button', 'reset'].includes(el.type)) {
            return;
        }

        const sent = values.get(el.name);
        if (el.type === 'checkbox' || el.type === 'radio') {
            // An unchecked checkbox isn't sent at all; a radio group with nothing sent stays as rendered.
            if (el.type === 'radio' && sent === undefined) return;
            const checked = Array.isArray(sent) ? sent.includes(el.value) : sent === el.value;
            if (el.checked !== checked) {
                el.checked = checked;
                fireEdited(el);
            }
            return;
        }

        const index = seen[el.name] = (seen[el.name] ?? -1) + 1;
        const value = Array.isArray(sent) ? sent[index] : sent;
        if (value === undefined || el.multiple) return;
        if (el.value !== value) {
            el.value = value;
            fireEdited(el);
        }
    });
}

/** The form that was submitted to `actionPath` — matched by its action, or a submit button's formaction. */
function findSubmittedForm(actionPath) {
    const pathOf = (url) => new URL(url || window.location.href, window.location.href).pathname;
    return Array.from(document.forms).find((f) => pathOf(f.getAttribute('action')) === actionPath)
        || Array.from(document.querySelectorAll('[formaction]'))
            .filter((b) => b.form && pathOf(b.getAttribute('formaction')) === actionPath)
            .map((b) => b.form)[0]
        || null;
}

/**
 * The elements to highlight for one field name: the literal field(s), else for "name[2]" the 3rd
 * "name[]" field. A checkbox/radio is represented by its whole group/row.
 */
function problemElementsFor(scope, name) {
    let fields = Array.from(scope.querySelectorAll('[name="' + CSS.escape(name) + '"]'));
    const indexed = name.match(/^(.*)\[(\d+)\]$/);
    if (fields.length === 0 && indexed) {
        const nth = scope.querySelectorAll('[name="' + CSS.escape(indexed[1] + '[]') + '"]')[Number(indexed[2])];
        fields = nth ? [nth] : [];
    }
    return fields.map((el) => (el.type === 'checkbox' || el.type === 'radio')
        ? (el.closest('.room-state-row, .form-group') || el.closest('label') || el)
        : el);
}

/** After a form rejected by the server (Controller::failForm): refill it, highlight what's wrong, scroll there. */
function initFormFeedback() {
    const data = formFeedbackData();
    if (!data.action) return;

    const form = findSubmittedForm(data.action);
    if (form && data.old) restoreSubmittedValues(form, data.old);

    const problems = [];
    (data.fields || []).forEach((name) => problems.push(...problemElementsFor(form || document, name)));
    (data.targets || []).forEach((id) => problems.push(document.getElementById(id)));
    showFormProblems(problems);
}

// After DOMContentLoaded *and* the page's own DOMContentLoaded handlers, so add-row buttons and
// change listeners the restore relies on are already wired.
document.addEventListener('DOMContentLoaded', () => setTimeout(initFormFeedback, 0));

// Browser-native validation (required, min, type=email…): tint the offending field too.
document.addEventListener('invalid', (e) => markProblem(e.target), true);

// Auto-init forms with data-track attribute (create forms don't need tracking).
// Runs on DOMContentLoaded since this script now loads in <head>, before the
// page body (and any page-specific inline scripts calling these functions) exists.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-track]').forEach(f => {
        if (f.id) initFormTracker(f.id);
    });
});

/**
 * Makes every table that has at least one `th[data-sort]` header sortable by clicking that
 * header: first click sorts ascending, clicking the same header again toggles descending/
 * ascending, clicking a different header starts that column fresh at ascending. Sorts by
 * the clicked column's `td[data-sort-value]` (falls back to the cell's own text) using a
 * plain string compare — good enough for both locale text and lexicographically-sortable
 * "YYYY-MM-DD HH:MM:SS" timestamps alike. Purely a client-side row reorder; nothing is re-fetched.
 *
 * The chosen column/direction is remembered per page (keyed by pathname + the table's position
 * among sortable tables on that page) and re-applied on the next visit without waiting for a click.
 */
function initSortableTables() {
    let tableIndex = 0;
    document.querySelectorAll('table').forEach((table) => {
        const headers = Array.from(table.querySelectorAll('th[data-sort]'));
        if (headers.length === 0) return;

        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const storageKey = 'bad-event:sort:' + location.pathname + ':' + tableIndex;
        tableIndex++;

        headers.forEach((th) => th.classList.add('sortable-col'));

        function sortByColumn(th, dir) {
            const columnIndex = Array.from(th.parentElement.children).indexOf(th);

            headers.forEach((h) => { if (h !== th) delete h.dataset.sortDir; });
            th.dataset.sortDir = dir;

            const rows = Array.from(tbody.rows);
            const cellValue = (row) => {
                const cell = row.children[columnIndex];
                if (!cell) return '';
                return cell.dataset.sortValue ?? cell.textContent.trim();
            };

            rows.sort((a, b) => {
                const cmp = cellValue(a).localeCompare(cellValue(b), 'cs');
                return dir === 'asc' ? cmp : -cmp;
            });
            rows.forEach((row) => tbody.appendChild(row));
        }

        headers.forEach((th) => {
            th.addEventListener('click', () => {
                const dir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';
                sortByColumn(th, dir);
                try {
                    localStorage.setItem(storageKey, JSON.stringify({
                        columnIndex: Array.from(th.parentElement.children).indexOf(th),
                        dir,
                    }));
                } catch {
                    // localStorage unavailable — sorting still works, just isn't remembered.
                }
            });
        });

        try {
            const saved = localStorage.getItem(storageKey);
            if (saved) {
                const { columnIndex, dir } = JSON.parse(saved);
                const th = headers.find((h) => Array.from(h.parentElement.children).indexOf(h) === columnIndex);
                if (th) sortByColumn(th, dir);
            }
        } catch {
            // Ignore a missing/corrupt saved state — table just stays in its default order.
        }
    });
}
document.addEventListener('DOMContentLoaded', initSortableTables);

/**
 * Adds "Označit vše" / "Neoznačit nic" controls before every .checkbox-grid on the page,
 * scoped to just that group's own (non-disabled) checkboxes. Generic and template-agnostic —
 * covers every current and future .checkbox-grid without touching individual view files.
 */
function initCheckboxGridControls() {
    document.querySelectorAll('.checkbox-grid').forEach((grid) => {
        const boxes = grid.querySelectorAll('input[type="checkbox"]:not(:disabled)');
        if (boxes.length === 0) return;

        const actions = document.createElement('div');
        actions.className = 'checkbox-grid-actions';
        actions.innerHTML = `
            <button type="button" class="btn btn-sm btn-ghost" data-action="check-all">Označit vše</button>
            <button type="button" class="btn btn-sm btn-ghost" data-action="check-none">Neoznačit nic</button>
        `;
        grid.parentNode.insertBefore(actions, grid);

        actions.querySelector('[data-action="check-all"]').addEventListener('click', () => {
            boxes.forEach((b) => { b.checked = true; });
        });
        actions.querySelector('[data-action="check-none"]').addEventListener('click', () => {
            boxes.forEach((b) => { b.checked = false; });
        });
    });
}
document.addEventListener('DOMContentLoaded', initCheckboxGridControls);

/**
 * Persists a page's own query string to localStorage whenever `triggerParam` is present in it
 * (meaning the visit reflects an explicit filter/navigation choice worth remembering), and
 * restores it via redirect on a "bare" visit (no query string at all) if something was saved
 * before. Must run as early as possible — call this inline, not inside DOMContentLoaded — so a
 * restore redirect happens before the page renders with the wrong (default) state.
 */
function rememberQueryOnLoad(storageKey, triggerParam) {
    const params = new URLSearchParams(location.search);
    if (params.has(triggerParam)) {
        localStorage.setItem(storageKey, location.search);
        return;
    }
    let saved = null;
    try {
        saved = localStorage.getItem(storageKey);
    } catch {
        return;
    }
    if (saved && saved !== location.search) {
        location.replace(location.pathname + saved);
    }
}

/**
 * Remembers a GET filter form's field values across visits when the results render somewhere
 * else (so the query string itself can't be replayed via redirect, unlike rememberQueryOnLoad).
 * Saves on submit, restores into the form's own fields on load if something was saved before —
 * checkboxes are matched by name+value, everything else by name.
 */
function rememberFormState(form, storageKey) {
    if (!form) return;

    let saved = null;
    try {
        saved = localStorage.getItem(storageKey);
    } catch {
        saved = null;
    }
    if (saved) {
        const params = new URLSearchParams(saved);
        form.querySelectorAll('input[type="checkbox"]').forEach((box) => {
            box.checked = params.getAll(box.name).includes(box.value);
        });
        form.querySelectorAll('input:not([type="checkbox"])').forEach((el) => {
            if (params.has(el.name)) el.value = params.get(el.name);
        });
    }

    form.addEventListener('submit', () => {
        try {
            localStorage.setItem(storageKey, new URLSearchParams(new FormData(form)).toString());
        } catch {
            // localStorage unavailable (private mode etc.) — nothing to persist, submit proceeds anyway.
        }
    });
}

/**
 * Wires up the room calendar's per-cell "▾" buttons: clicking one renders an hour-by-hour
 * timeline for that room/day below the month grid (one row per action, so overlaps are visible),
 * built entirely from the JSON already embedded in the page — no extra request.
 */
function initPlaceDayCalendar() {
    const dataEl = document.getElementById('places-calendar-data');
    const panel  = document.getElementById('place-day-detail');
    if (!dataEl || !panel) return;

    let byPlaceAndDay;
    try {
        byPlaceAndDay = JSON.parse(dataEl.textContent);
    } catch {
        return;
    }

    const actionsBase  = panel.dataset.actionsBase || '';
    const DAY_MINUTES  = 24 * 60;
    const WEEKDAYS     = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s == null ? '' : String(s);
        return div.innerHTML;
    }

    // "YYYY-MM-DD HH:MM:SS" → its date part, for comparing against the day being rendered.
    function dateOnly(dateTime) {
        return dateTime.slice(0, 10);
    }

    // "YYYY-MM-DD HH:MM:SS" → minutes since midnight of *that same* timestamp's own date.
    function minutesOfDay(dateTime) {
        return Number(dateTime.slice(11, 13)) * 60 + Number(dateTime.slice(14, 16));
    }

    // A timestamp's position on `viewDate`'s timeline: 0 if it actually falls on an earlier
    // date (the bar should run flush to the grid's start) or DAY_MINUTES if it falls on a later
    // date (flush to the grid's end) — this is how a bar for an action spanning midnight gets
    // drawn "open-ended" on every day it touches, instead of collapsing to a single instant.
    function positionMinutes(dateTime, viewDate) {
        const d = dateOnly(dateTime);
        if (d < viewDate) return 0;
        if (d > viewDate) return DAY_MINUTES;
        return minutesOfDay(dateTime);
    }

    function timeLabel(dateTime, viewDate) {
        const time = dateTime.slice(11, 16);
        if (dateOnly(dateTime) === viewDate) return time;
        const [, m, d] = dateTime.slice(0, 10).split('-');
        return `${d}. ${m}. ${time}`;
    }

    function formatDayHeading(date) {
        const [y, m, d] = date.split('-').map(Number);
        const jsDate = new Date(y, m - 1, d);
        return `${WEEKDAYS[jsDate.getDay()]} ${d}. ${m}. ${y}`;
    }

    function renderBar(fromDateTime, toDateTime, viewDate, extraClass) {
        const clampedStart = dateOnly(fromDateTime) < viewDate;
        const clampedEnd   = dateOnly(toDateTime)   > viewDate;
        const from  = positionMinutes(fromDateTime, viewDate);
        const to    = Math.max(positionMinutes(toDateTime, viewDate), from);
        const left  = (from / DAY_MINUTES) * 100;
        const width = Math.max(((to - from) / DAY_MINUTES) * 100, 0.4);
        const clampClass = (clampedStart ? ' day-timeline-bar-clamped-start' : '') +
                            (clampedEnd   ? ' day-timeline-bar-clamped-end'   : '');
        return `<div class="day-timeline-bar ${extraClass}${clampClass}" style="left:${left}%;width:${width}%" ` +
               `title="${timeLabel(fromDateTime, viewDate)}–${timeLabel(toDateTime, viewDate)}"></div>`;
    }

    function renderRow(action, viewDate) {
        // A confirmed (non-queued) room booking is colored by the action's own status — same
        // palette as the status badges/calendar elsewhere (green=confirmed, purple=draft, …).
        // Queued (requested-only) bookings keep the existing dashed/orange styling regardless of status.
        // Namespaced as "day-timeline-status-*" (not the bare "status-*" used by .status-badge
        // elsewhere) because this class also lands on .day-timeline-track below, which isn't
        // scoped to .day-timeline-bar — a bare "status-draft" etc. class there would match the
        // site-wide .status-draft{background:...} badge rule and tint the whole 24h track.
        const stateClass = action.is_queued ? 'queued' : 'day-timeline-status-' + action.status_code;
        const badge = action.is_queued
            ? `<span class="day-timeline-queue-badge" title="Pořadí ve frontě">#${escapeHtml(action.queue_position ?? '?')}</span>`
            : `<span class="day-timeline-confirmed-badge" title="Místnost zabraná">✓</span>`;

        // public_start_at is always present for actions the server includes here; the other
        // three timestamps are nullable in the DB, so each falls back to the next-best one.
        const pubFrom   = action.public_start_at;
        const pubTo     = action.public_end_at || pubFrom;
        const staffFrom = action.staff_start_at || pubFrom;
        const staffTo   = action.staff_end_at   || pubTo;

        return `
            <div class="day-timeline-row">
                <div class="day-timeline-row-label">
                    <a href="${actionsBase}/${encodeURIComponent(action.id)}">${escapeHtml(action.name)}</a>
                    ${badge}
                </div>
                <div class="day-timeline-track ${stateClass}">
                    ${renderBar(staffFrom, staffTo, viewDate, 'day-timeline-bar-staff ' + stateClass)}
                    ${renderBar(pubFrom, pubTo, viewDate, 'day-timeline-bar-public ' + stateClass)}
                </div>
            </div>`;
    }

    function renderRuler() {
        let hours = '';
        for (let h = 0; h <= 24; h++) {
            hours += `<div class="day-timeline-hour" style="left:${(h / 24) * 100}%">${h < 24 ? String(h).padStart(2, '0') : ''}</div>`;
        }
        // Spacer matches .day-timeline-row-label's width so the hour ticks line up with the tracks below.
        return `<div class="day-timeline-ruler-spacer"></div><div class="day-timeline-ruler-track">${hours}</div>`;
    }

    function render(placeName, placeAbbr, date, actions) {
        const title = placeAbbr ? `${placeName} (${placeAbbr})` : placeName;
        const rows  = actions.length
            ? actions.map((a) => renderRow(a, date)).join('')
            : '<p class="empty-state">V tento den nejsou v místnosti žádné akce.</p>';

        panel.innerHTML = `
            <div class="place-day-detail-header">
                <h3>${escapeHtml(title)} — ${escapeHtml(formatDayHeading(date))}</h3>
                <button type="button" class="btn btn-sm btn-ghost" data-action="close-day-detail">Zavřít ✕</button>
            </div>
            <div class="day-timeline">
                <div class="day-timeline-ruler">${renderRuler()}</div>
                <div class="day-timeline-rows">${rows}</div>
            </div>`;
        panel.hidden = false;
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    document.querySelectorAll('.place-day-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const placeId = btn.dataset.placeId;
            const date    = btn.dataset.date;
            const actions = (byPlaceAndDay[placeId] && byPlaceAndDay[placeId][date]) || [];
            render(btn.dataset.placeName, btn.dataset.placeAbbr, date, actions);
        });
    });

    panel.addEventListener('click', (e) => {
        if (e.target.closest('[data-action="close-day-detail"]')) {
            panel.hidden = true;
            panel.innerHTML = '';
        }
    });
}

document.addEventListener('DOMContentLoaded', initPlaceDayCalendar);
