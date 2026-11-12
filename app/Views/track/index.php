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
// Progress steps for the stepper: Registered → In transit → Received → Completed.
$order = ['draft' => 0, 'in_transit' => 1, 'received' => 2, 'completed' => 3];
$stage = $info !== null ? ($order[$info['status']] ?? -1) : -1;
$steps = ['Registered', 'In transit', 'Received', 'Completed'];
?>
<div class="track-page mx-auto">
    <div class="text-center mb-4 track-hero-title">
        <h1 class="h3 mb-1 text-white">Track a document</h1>
        <p class="mb-0 text-white-50">Enter the tracking number printed on the document or its QR label.</p>
    </div>

    <form class="card track-search mb-4" method="get" action="<?= e(url('/track')) ?>" role="search">
        <label for="no" class="form-label">Tracking number</label>
        <div class="d-flex flex-column flex-sm-row gap-2">
            <div class="position-relative flex-grow-1">
                <input type="text" class="form-control form-control-lg font-monospace text-uppercase ps-5" id="no" name="no"
                       value="<?= e($trackingNo) ?>" placeholder="VVU-REG-2026-00042" required maxlength="30"
                       autocomplete="off" autocapitalize="characters" spellcheck="false">
                <i class="bi bi-upc-scan input-icon track-input-icon" aria-hidden="true"></i>
            </div>
            <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Track</button>
        </div>
    </form>

    <?php if ($searched && $info === null): ?>
        <div class="card">
            <div class="empty-state">
                <div class="empty-icon"><i class="bi bi-question-lg" aria-hidden="true"></i></div>
                <h2 class="h6 mb-1">No document found</h2>
                <p class="mb-0 small">There is no document with the tracking number <strong class="font-monospace"><?= e($trackingNo) ?></strong>. Check the number and try again.</p>
            </div>
        </div>
    <?php elseif ($info !== null): ?>
        <div class="card">
            <div class="card-body p-4">
                <div class="track-result-head">
                    <div class="min-w-0">
                        <div class="font-monospace fw-bold fs-5 text-break"><?= e($info['tracking_no']) ?></div>
                        <?php if ($info['title'] !== null): ?>
                            <div class="text-break text-body-secondary"><?= e($info['title']) ?></div>
                        <?php else: ?>
                            <div class="text-body-secondary fst-italic"><i class="bi bi-lock" aria-hidden="true"></i> Confidential document</div>
                        <?php endif; ?>
                    </div>
                    <?= partial('partials/status-badge', ['status' => $info['status']]) ?>
                </div>

                <?php if ($stage >= 0): ?>
                    <ol class="track-steps" aria-label="Progress">
                        <?php foreach ($steps as $i => $label): ?>
                            <li class="<?= $i < $stage ? 'is-done' : ($i === $stage ? ($stage === 3 ? 'is-done' : 'is-current') : '') ?>"
                                <?= $i === $stage ? 'aria-current="step"' : '' ?>><?= e($label) ?></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <dl class="track-facts">
                    <div class="track-fact">
                        <dt><?= $info['in_transit_to'] !== null ? 'On its way to' : 'Current location' ?></dt>
                        <dd><i class="bi bi-geo-alt text-primary" aria-hidden="true"></i> <?= e($info['in_transit_to'] ?? $info['current_department']) ?></dd>
                    </div>
                    <div class="track-fact">
                        <dt>Status</dt>
                        <dd><?= e($statusText[$info['status']] ?? $info['status']) ?></dd>
                    </div>
                    <div class="track-fact">
                        <dt>Document type</dt>
                        <dd><?= e($info['type_name']) ?></dd>
                    </div>
                    <div class="track-fact">
                        <dt>Last updated</dt>
                        <dd><?= time_ago($info['last_updated']) ?></dd>
                    </div>
                </dl>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3 text-center small text-body-secondary">
            <div class="col-sm-4"><div class="card card-body h-100"><i class="bi bi-qr-code-scan fs-4 text-primary mb-1" aria-hidden="true"></i>Scan the QR label with your phone camera</div></div>
            <div class="col-sm-4"><div class="card card-body h-100"><i class="bi bi-geo-alt fs-4 text-primary mb-1" aria-hidden="true"></i>See which office has the document now</div></div>
            <div class="col-sm-4"><div class="card card-body h-100"><i class="bi bi-shield-lock fs-4 text-primary mb-1" aria-hidden="true"></i>Confidential details stay private</div></div>
        </div>
    <?php endif; ?>

    <p class="text-center small text-body-secondary mt-4 mb-0">
        VVU staff: <a href="<?= e(url('/login')) ?>">sign in</a> to see full details and act on documents.
    </p>
</div>
