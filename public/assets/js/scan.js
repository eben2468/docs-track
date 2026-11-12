/* QR scanner page: reads a document label and opens /track/{tracking_no}, which takes a
 * signed-in user to the document (with the "Receive this document" button when it is
 * on its way to their department). Uses the html5-qrcode library (local copy). */
(function () {
    'use strict';

    var reader = document.getElementById('qrReader');
    if (!reader || typeof window.Html5Qrcode === 'undefined') {
        return;
    }

    var startBtn = document.getElementById('scanStart');
    var stopBtn = document.getElementById('scanStop');
    var fileInput = document.getElementById('scanFile');
    var errorBox = document.getElementById('scanError');
    var resultBox = document.getElementById('scanResult');
    var trackBase = reader.getAttribute('data-track-base');
    var scanner = new window.Html5Qrcode('qrReader', {verbose: false});
    var running = false;
    var done = false;

    function show(box, message) {
        box.textContent = message;
        box.classList.remove('d-none');
    }

    function hide(box) {
        box.classList.add('d-none');
    }

    /** Extract a tracking number from a scanned URL (…/track/VVU-REG-2026-00042) or plain text. */
    function trackingNumberFrom(text) {
        var match = String(text).toUpperCase().match(/VVU-[A-Z]{2,6}-\d{4}-\d{5,}/);
        return match ? match[0] : null;
    }

    function handle(text) {
        if (done) {
            return;
        }
        var trackingNo = trackingNumberFrom(text);
        if (!trackingNo) {
            show(errorBox, 'That QR code is not a VVU document label.');
            return;
        }
        done = true;
        hide(errorBox);
        show(resultBox, 'Found ' + trackingNo + '. Opening…');
        if (navigator.vibrate) {
            navigator.vibrate(100);
        }
        var go = function () {
            window.location.href = trackBase + encodeURIComponent(trackingNo);
        };
        if (running) {
            scanner.stop().then(go, go);
        } else {
            go();
        }
    }

    function setRunning(state) {
        running = state;
        startBtn.classList.toggle('d-none', state);
        stopBtn.classList.toggle('d-none', !state);
    }

    startBtn.addEventListener('click', function () {
        hide(errorBox);
        if (!window.isSecureContext) {
            show(errorBox, 'The camera only works over HTTPS. Use "scan a photo of the label" below, or type the number.');
            return;
        }
        reader.innerHTML = '';
        scanner.start(
            {facingMode: 'environment'},
            {fps: 10, qrbox: function (w, h) { var s = Math.floor(Math.min(w, h) * 0.7); return {width: s, height: s}; }},
            handle,
            function () { /* no code in this frame */ }
        ).then(function () {
            setRunning(true);
        }).catch(function (err) {
            setRunning(false);
            var msg = String(err && err.name ? err.name : err);
            show(errorBox, /NotAllowed|Permission/i.test(msg)
                ? 'Camera permission was denied. Allow camera access for this site in your browser settings, or scan a photo instead.'
                : 'Could not start the camera (' + msg + '). You can scan a photo of the label instead.');
        });
    });

    stopBtn.addEventListener('click', function () {
        scanner.stop().then(function () { setRunning(false); }, function () { setRunning(false); });
    });

    fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) {
            return;
        }
        hide(errorBox);
        var scan = function () {
            scanner.scanFile(file, false).then(handle).catch(function () {
                show(errorBox, 'No QR code found in that photo. Try again with the label filling more of the picture.');
            });
        };
        if (running) {
            scanner.stop().then(function () { setRunning(false); scan(); }, scan);
        } else {
            scan();
        }
    });
})();
