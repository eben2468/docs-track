/* VVU Document Tracking System: shared front-end behaviour (vanilla JS, CSP-safe: no inline handlers). */
(function () {
    'use strict';

    /** CSRF token from the <meta name="csrf-token"> tag, for AJAX POSTs (sent as X-CSRF-Token). */
    window.DTS = window.DTS || {};
    window.DTS.csrfToken = function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    /* Buttons with data-action="print" / "back". */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-action]');
        if (!trigger) {
            return;
        }

        switch (trigger.getAttribute('data-action')) {
            case 'print':
                event.preventDefault();
                window.print();
                break;
            case 'back':
                event.preventDefault();
                window.history.back();
                break;
        }
    });

    /*
     * Forms with data-confirm="Question?" ask in a Bootstrap modal before submitting.
     * Optional data-confirm-button (label) and data-confirm-variant (danger|primary|warning).
     * Without JavaScript or the modal, the form simply submits.
     */
    var modalEl = document.getElementById('confirmModal');
    var pendingForm = null;

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.hasAttribute('data-confirm') || form.dataset.confirmed === '1' || !modalEl || !window.bootstrap) {
            return;
        }
        event.preventDefault();
        pendingForm = form;

        modalEl.querySelector('[data-confirm-message]').textContent = form.getAttribute('data-confirm');
        var accept = modalEl.querySelector('[data-confirm-accept]');
        accept.textContent = form.getAttribute('data-confirm-button') || 'Confirm';
        accept.className = 'btn btn-' + (form.getAttribute('data-confirm-variant') || 'primary');

        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    if (modalEl) {
        modalEl.querySelector('[data-confirm-accept]').addEventListener('click', function () {
            if (!pendingForm) {
                return;
            }
            pendingForm.dataset.confirmed = '1';
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            if (typeof pendingForm.requestSubmit === 'function') {
                pendingForm.requestSubmit();
            } else {
                pendingForm.submit();
            }
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            pendingForm = null;
        });
    }

    /* <select data-auto-submit> submits its form when changed. */
    document.addEventListener('change', function (event) {
        if (event.target.matches('select[data-auto-submit]') && event.target.form) {
            event.target.form.submit();
        }
    });

    /* Colour pickers: show the hex value next to the swatch. */
    document.querySelectorAll('input[type="color"]').forEach(function (input) {
        var label = input.parentElement.querySelector('[data-color-value]');
        if (label) {
            input.addEventListener('input', function () {
                label.textContent = input.value;
            });
        }
    });
})();
