<?php
/**
 * Temporary home page until the dashboards are built: greeting plus a system check.
 *
 * @var array<string, array{0: bool, 1: string}> $checks
 */
$user = auth();
?>
<div class="mb-4">
    <h1 class="h4 mb-1">Welcome, <?= e($user['full_name'] ?? '') ?></h1>
    <p class="text-body-secondary mb-0">Document registration, tracking and dashboards will appear here in upcoming releases.</p>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-body"><h2 class="h6 mb-0">System check</h2></div>
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
