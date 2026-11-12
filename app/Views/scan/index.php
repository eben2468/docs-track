<?php
/**
 * QR scanner (spec §5.2). The camera needs HTTPS (or localhost); scanning a photo of
 * the label and typing the number work everywhere.
 */
?>
<div class="scan-page mx-auto">
    <h1 class="h4 mb-1 text-center">Scan QR label</h1>
    <p class="text-body-secondary text-center small mb-3">Point your camera at the QR code on a document label.</p>

    <div class="card shadow-sm mb-3">
        <div class="card-body p-2">
            <div id="qrReader" class="scan-viewport" data-track-base="<?= e(url('/track/')) ?>" aria-live="polite">
                <div class="scan-placeholder text-center text-body-secondary p-4">
                    <i class="bi bi-camera fs-1 d-block mb-2" aria-hidden="true"></i>
                    Tap <strong>Start camera</strong> to begin.
                </div>
            </div>
        </div>
        <div class="card-footer bg-body d-grid gap-2">
            <button type="button" class="btn btn-primary btn-lg" id="scanStart"><i class="bi bi-camera-video" aria-hidden="true"></i> Start camera</button>
            <button type="button" class="btn btn-outline-secondary d-none" id="scanStop"><i class="bi bi-stop-circle" aria-hidden="true"></i> Stop camera</button>
        </div>
    </div>

    <div class="alert alert-danger d-none" id="scanError" role="alert"></div>
    <div class="alert alert-success d-none" id="scanResult" role="status"></div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <label for="scanFile" class="form-label small fw-semibold"><i class="bi bi-image" aria-hidden="true"></i> Or scan a photo of the label</label>
            <input type="file" class="form-control" id="scanFile" accept="image/*" capture="environment">
        </div>
    </div>

    <form class="card shadow-sm" method="get" action="<?= e(url('/search')) ?>">
        <div class="card-body">
            <label for="scanManual" class="form-label small fw-semibold"><i class="bi bi-keyboard" aria-hidden="true"></i> Or type the tracking number</label>
            <div class="input-group">
                <input type="text" class="form-control font-monospace text-uppercase" id="scanManual" name="q" placeholder="VVU-REG-2026-00042"
                       maxlength="30" autocapitalize="characters" spellcheck="false" required>
                <button class="btn btn-outline-primary" type="submit">Open</button>
            </div>
        </div>
    </form>
</div>
<script src="<?= e(asset('js/vendor/html5-qrcode.min.js')) ?>"></script>
<script src="<?= e(asset('js/scan.js')) ?>"></script>
