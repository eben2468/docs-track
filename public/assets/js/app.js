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

    /* <input data-filter-select="selectId">: hide options of that select that don't match the typed text. */
    document.querySelectorAll('input[data-filter-select]').forEach(function (input) {
        var select = document.getElementById(input.getAttribute('data-filter-select'));
        if (!select) {
            return;
        }
        input.addEventListener('input', function () {
            var term = input.value.trim().toLowerCase();
            Array.prototype.forEach.call(select.options, function (option) {
                option.hidden = term !== '' && option.textContent.toLowerCase().indexOf(term) === -1;
            });
        });
    });

    /*
     * <select data-recipients-target="userSelectId" data-recipients-url="/api/departments/">:
     * when a department is chosen, load its active users into the recipient select.
     */
    document.querySelectorAll('select[data-recipients-target]').forEach(function (deptSelect) {
        var target = document.getElementById(deptSelect.getAttribute('data-recipients-target'));
        var baseUrl = deptSelect.getAttribute('data-recipients-url');
        if (!target || !baseUrl) {
            return;
        }

        function reset(label) {
            target.innerHTML = '';
            var option = document.createElement('option');
            option.value = '';
            option.textContent = label;
            target.appendChild(option);
        }

        function load() {
            var id = deptSelect.value;
            reset('Anyone in the department');
            target.disabled = true;
            if (!id) {
                return;
            }
            fetch(baseUrl + encodeURIComponent(id) + '/users', {
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            }).then(function (data) {
                (data.users || []).forEach(function (user) {
                    var option = document.createElement('option');
                    option.value = String(user.id);
                    option.textContent = user.name + ' (' + user.role + ')';
                    target.appendChild(option);
                });
                target.disabled = false;
            }).catch(function () {
                reset('Could not load people; the whole department will be notified');
            });
        }

        deptSelect.addEventListener('change', load);
        if (deptSelect.value) {
            load();
        }
    });

    /* Re-open a modal whose form came back with validation errors. */
    if (window.bootstrap) {
        document.querySelectorAll('.modal').forEach(function (modal) {
            if (modal.querySelector('.is-invalid')) {
                window.bootstrap.Modal.getOrCreateInstance(modal).show();
            }
        });
    }

    /* "Select all" checkbox: <input type="checkbox" data-check-all="name[]">. */
    document.addEventListener('change', function (event) {
        var name = event.target.getAttribute && event.target.getAttribute('data-check-all');
        if (name) {
            document.querySelectorAll('input[type="checkbox"][name="' + name + '"]').forEach(function (box) {
                box.checked = event.target.checked;
            });
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
