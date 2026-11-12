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

    /*
     * Notification bell: poll the unread count (and latest five) every 60 seconds while the
     * tab is visible, and render the dropdown list. All text is set with textContent.
     */
    var bell = document.getElementById('notifBell');
    if (bell && window.fetch) {
        var pollUrl = bell.getAttribute('data-poll-url');
        var badge = bell.querySelector('[data-notif-count]');
        var label = bell.querySelector('[data-notif-label]');
        var list = bell.querySelector('[data-notif-list]');
        var icons = {
            incoming: 'inbox', received: 'box-arrow-in-down', returned: 'arrow-return-left', recalled: 'arrow-counterclockwise',
            completed: 'check2-circle', unreceived_alert: 'hourglass-split', overdue_alert: 'alarm'
        };

        var render = function (data) {
            var count = data.count || 0;
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.classList.toggle('d-none', count === 0);
            label.textContent = count + ' unread';

            list.innerHTML = '';
            if (!data.latest || data.latest.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'px-3 py-3 small text-body-secondary';
                empty.textContent = 'No notifications yet.';
                list.appendChild(empty);
                return;
            }
            data.latest.forEach(function (n) {
                var a = document.createElement('a');
                a.href = n.url;
                a.className = 'notif-item d-flex gap-2 px-3 py-2' + (n.is_read ? '' : ' is-unread');
                var icon = document.createElement('i');
                icon.className = 'bi bi-' + (icons[n.type] || 'bell') + ' notif-icon notif-' + n.type;
                icon.setAttribute('aria-hidden', 'true');
                var body = document.createElement('div');
                body.className = 'min-w-0';
                var msg = document.createElement('div');
                msg.className = 'notif-message';
                msg.textContent = n.message;
                var meta = document.createElement('div');
                meta.className = 'small text-body-secondary';
                meta.textContent = n.time + (n.tracking_no ? ' · ' + n.tracking_no : '');
                body.appendChild(msg);
                body.appendChild(meta);
                a.appendChild(icon);
                a.appendChild(body);
                list.appendChild(a);
            });
        };

        var poll = function () {
            if (document.hidden) {
                return;
            }
            fetch(pollUrl, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
                .then(function (r) {
                    if (r.status === 401) {
                        window.clearInterval(timer);
                        throw new Error('signed out');
                    }
                    return r.json();
                })
                .then(render)
                .catch(function () { /* keep the last known state */ });
        };

        var timer = window.setInterval(poll, 60000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                poll();
            }
        });
        poll();
    }

    /* Top-bar search: "/" focuses it; on phones the search icon opens it below the bar. */
    var search = document.getElementById('appSearch');
    if (search) {
        var searchInput = search.querySelector('input[type="search"]');
        var searchToggle = document.querySelector('[data-search-toggle]');
        var openSearch = function (open) {
            search.classList.toggle('is-open', open);
            if (searchToggle) {
                searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            if (open) {
                searchInput.focus();
            }
        };
        if (searchToggle) {
            searchToggle.addEventListener('click', function () {
                openSearch(!search.classList.contains('is-open'));
            });
        }
        document.addEventListener('keydown', function (event) {
            var tag = (event.target.tagName || '').toLowerCase();
            var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable;
            if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                openSearch(true);
            } else if (event.key === 'Escape' && search.classList.contains('is-open')) {
                openSearch(false);
            }
        });
    }

    /* Show/hide password: <button data-toggle-password="inputId">. */
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        var input = document.getElementById(button.getAttribute('data-toggle-password'));
        if (!input) {
            return;
        }
        button.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            var icon = button.querySelector('.bi');
            if (icon) {
                icon.className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
            }
        });
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
