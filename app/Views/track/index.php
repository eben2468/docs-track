<?php
/**
 * Public tracking page (FR-14). Shows only: tracking no., type, status, current location,
 * last updated, and the title for non-confidential documents.
 *
 * @var string $trackingNo
 * @var array<string, mixed>|null $info From Document::publicSummary()
 * @var bool|null $searched
 */
$searched ??= false;
$info ??= null;
$statusText = [
    'draft' => 'Registered (not yet sent)',
    'in_transit' => 'In transit',
    'received' => 'Received',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
?>
<div class="track-page mx-auto">
    <div class="text-center mb-4">
        <h1 class="h3 mb-1">Track a document</h1>
        <p class="text-body-secondary mb-0">Enter the tracking number printed on the document or its QR label.</p>
    </div>

    <form class="card card-body shadow-sm mb-4" method="get" action="<?= e(url('/track')) ?>" role="search">
        <label for="no" class="form-label">Tracking number</label>
        <div class="input-group">
            <input type="text" class="form-control form-control-lg font-monospace text-uppercase" id="no" name="no"
                   value="<?= e($trackingNo) ?>" placeholder="VVU-REG-2026-00042" required maxlength="30"
                   autocomplete="off" autocapitalize="characters" spellcheck="false">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Track</button>
        </div>
    </form>

    <?php if ($searched && $info === null): ?>
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>No document was found with the tracking number <strong class="font-monospace"><?= e($trackingNo) ?></strong>. Check the number and try again.</div>
        </div>
    <?php elseif ($info !== null): ?>
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <div class="font-monospace fw-bold fs-5"><?= e($info['tracking_no']) ?></div>
                        <?php if ($info['title'] !== null): ?>
                            <div class="text-break"><?= e($info['title']) ?></div>
                        <?php else: ?>
                            <div class="text-body-secondary fst-italic"><i class="bi bi-lock" aria-hidden="true"></i> Confidential document</div>
                        <?php endif; ?>
                    </div>
                    <?= partial('partials/status-badge', ['status' => $info['status']]) ?>
                </div>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Document type</dt><dd class="col-sm-8"><?= e($info['type_name']) ?></dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= e($statusText[$info['status']] ?? $info['status']) ?></dd>
                    <dt class="col-sm-4"><?= $info['in_transit_to'] !== null ? 'On its way to' : 'Current location' ?></dt>
                    <dd class="col-sm-8 fw-semibold"><?= e($info['in_transit_to'] ?? $info['current_department']) ?></dd>
                    <dt class="col-sm-4">Last updated</dt><dd class="col-sm-8 mb-0"><?= time_ago($info['last_updated']) ?></dd>
                </dl>
            </div>
        </div>
    <?php endif; ?>

    <p class="text-center small text-body-secondary mt-4 mb-0">
        VVU staff: <a href="<?= e(url('/login')) ?>">sign in</a> to see full details and act on documents.
    </p>
</div>
