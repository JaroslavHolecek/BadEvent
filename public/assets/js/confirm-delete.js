'use strict';

/**
 * Guards forms matching `selector` behind a modal that requires typing `word` before the form
 * is allowed to submit. Shared by the delete-confirmation gate (word "smazat") and the
 * dictionary-edit gate (word "zmenit") — each call builds its own independent modal instance.
 */
function createConfirmGate({ selector, word, title, confirmLabel, buttonClass, defaultMessage }) {
    const forms = document.querySelectorAll(selector);
    if (forms.length === 0) return;

    let pendingForm = null;

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.style.display = 'none';
    overlay.innerHTML = `
        <div class="modal-box">
            <h3>${title}</h3>
            <p class="modal-message"></p>
            <label class="checkbox-label modal-extra-checkbox" style="display:none;align-items:center;gap:.4rem;margin-bottom:.75rem;">
                <input type="checkbox">
                <span class="modal-extra-checkbox-label"></span>
            </label>
            <p>Pro potvrzení napište slovo „<strong>${word}</strong>“:</p>
            <input type="text" autocomplete="off" spellcheck="false">
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-action="cancel">Zrušit</button>
                <button type="button" class="btn ${buttonClass}" data-action="confirm" disabled>${confirmLabel}</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const messageEl        = overlay.querySelector('.modal-message');
    const extraCheckboxRow = overlay.querySelector('.modal-extra-checkbox');
    const extraCheckboxEl  = extraCheckboxRow.querySelector('input');
    const extraCheckboxLbl = extraCheckboxRow.querySelector('.modal-extra-checkbox-label');
    const inputEl    = overlay.querySelector('input[type="text"]');
    const confirmBtn = overlay.querySelector('[data-action="confirm"]');
    const cancelBtn  = overlay.querySelector('[data-action="cancel"]');

    function closeModal() {
        overlay.style.display = 'none';
        inputEl.value = '';
        confirmBtn.disabled = true;
        extraCheckboxRow.style.display = 'none';
        extraCheckboxEl.onchange = null;
        pendingForm = null;
    }

    inputEl.addEventListener('input', () => {
        confirmBtn.disabled = inputEl.value.trim().toLowerCase() !== word;
    });

    confirmBtn.addEventListener('click', () => {
        if (confirmBtn.disabled || !pendingForm) return;
        const form = pendingForm;
        closeModal();
        form.submit();
    });

    cancelBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay.style.display !== 'none') closeModal();
    });

    forms.forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            pendingForm = form;
            messageEl.textContent = form.dataset.confirmMessage || defaultMessage;

            // A form can opt into an extra checkbox shown inside this modal (e.g. "also delete
            // the linked person") via data-extra-checkbox-name/-label — the real, hidden input of
            // that name already lives in the form itself; the modal's checkbox just mirrors it,
            // since it's this shared modal (not the underlying page) the user actually interacts with.
            const extraName = form.dataset.extraCheckboxName;
            const targetCheckbox = extraName
                ? form.querySelector(`input[type="checkbox"][name="${extraName}"]`)
                : null;
            if (targetCheckbox) {
                extraCheckboxLbl.textContent = form.dataset.extraCheckboxLabel || '';
                extraCheckboxEl.checked = targetCheckbox.checked;
                extraCheckboxEl.onchange = () => { targetCheckbox.checked = extraCheckboxEl.checked; };
                extraCheckboxRow.style.display = 'flex';
            } else {
                extraCheckboxRow.style.display = 'none';
            }

            overlay.style.display = 'flex';
            inputEl.focus();
        });
    });
}

function initConfirmDeleteForms() {
    createConfirmGate({
        selector: 'form.confirm-delete-form',
        word: 'smazat',
        title: 'Potvrzení smazání',
        confirmLabel: 'Smazat',
        buttonClass: 'btn-danger',
        defaultMessage: 'Opravdu chcete tuto položku trvale smazat?',
    });
}

function initConfirmEditForms() {
    createConfirmGate({
        selector: 'form.confirm-edit-form',
        word: 'zmenit',
        title: 'Potvrzení úpravy',
        confirmLabel: 'Upravit',
        buttonClass: 'btn-warning',
        defaultMessage: 'Úpravou změníte text nejen pro budoucí položky, ale TAKÉ PRO VŠECHNY MINULÉ.',
    });
}

/**
 * Lets an administrator push an action status transition through despite missing required data
 * (no confirmed room / no attendance / no economic data — see ActionWorkflowService). The form's
 * `data-warning` (set server-side only for admins, only when data is actually missing — see
 * ActionController::transitionWarnings) supplies the message; confirming sets the form's hidden
 * `force` field, which ActionController::transition then trusts only after re-checking isAdmin.
 */
function initTransitionWarningForms() {
    const forms = document.querySelectorAll('form.transition-form[data-warning]');
    if (forms.length === 0) return;

    let pendingForm = null;

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.style.display = 'none';
    overlay.innerHTML = `
        <div class="modal-box">
            <h3>Chybí povinné údaje</h3>
            <p class="modal-message"></p>
            <p>Jako administrátor můžete stav přesto změnit.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-action="cancel">Zrušit</button>
                <button type="button" class="btn btn-warning" data-action="confirm">Přesto pokračovat</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const messageEl  = overlay.querySelector('.modal-message');
    const confirmBtn = overlay.querySelector('[data-action="confirm"]');
    const cancelBtn  = overlay.querySelector('[data-action="cancel"]');

    function closeModal() {
        overlay.style.display = 'none';
        pendingForm = null;
    }

    confirmBtn.addEventListener('click', () => {
        if (!pendingForm) return;
        const form = pendingForm;
        const forceInput = form.querySelector('input[name="force"]');
        if (forceInput) forceInput.value = '1';
        closeModal();
        form.submit();
    });

    cancelBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay.style.display !== 'none') closeModal();
    });

    forms.forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            pendingForm = form;
            messageEl.textContent = form.dataset.warning;
            overlay.style.display = 'flex';
        });
    });
}

// Runs on DOMContentLoaded since this script now loads in <head>, before the
// page body (where any guarded forms live) exists.
document.addEventListener('DOMContentLoaded', () => {
    initConfirmDeleteForms();
    initConfirmEditForms();
    initTransitionWarningForms();
});
