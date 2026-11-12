<?php
/**
 * Temporary Phase 0 page: shows that layout, routing, config and DB are wired up.
 *
 * @var array<string, array{0: bool, 1: string}> $checks
 */
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h4 mb-1">Foundation check</h1>
        <p class="text-body-secondary mb-0">The application skeleton is running.</p>
    </div>
</div>

<div class="card shadow-sm">
    <div class="list-group list-group-flush">
        <?php foreach ($checks as $label => [$ok, $detail]): ?>
            <div class="list-group-item d-flex align-items-center gap-3 py-3">
                <i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?> fs-5"
                   aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= e($label) ?></div>
                    <div class="small text-body-secondary"><?= e($detail) ?></div>
                </div>
                <span class="badge <?= $ok ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $ok ? 'OK' : 'Failed' ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
