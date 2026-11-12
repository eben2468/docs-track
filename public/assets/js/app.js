/* VVU Document Tracking System: shared front-end behaviour (vanilla JS, CSP-safe: no inline handlers). */
(function () {
    'use strict';

    /** CSRF token from the <meta name="csrf-token"> tag, for AJAX POSTs (sent as X-CSRF-Token). */
    window.DTS = window.DTS || {};
    window.DTS.csrfToken = function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

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
})();
