<?php
/**
 * Shown right after registration (FR-4): next steps.
 *
 * @var array<string, mixed> $document
 */
$id = (int) $document['id'];
?>
<div class="card shadow-sm registered-card mx-auto text-center">
    <div class="card-body p-4 p-md-5">
        <div class="registered-icon mb-3"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></div>
        <h1 class="h4">Document registered</h1>
        <p class="text-body-secondary mb-2">Write this tracking number on the document or attach its QR label.</p>
        <p class="tracking-no-display font-monospace mb-1"><?= e($document['tracking_no']) ?></p>
        <p class="mb-4"><?= partial('partials/status-badge', ['status' => $document['status']]) ?>
            <span class="text-body-secondary small ms-1"><?= e($document['type_name']) ?> · <?= e($document['origin_department_name']) ?></span>
        </p>

        <div class="d-flex flex-wrap justify-content-center gap-2">
            <a class="btn btn-primary" href="<?= e(url("/documents/$id/label")) ?>" target="_blank" rel="noopener">
                <i class="bi bi-qr-code" aria-hidden="true"></i> Print QR label
            </a>
            <a class="btn btn-outline-primary" href="<?= e(url("/documents/$id")) ?>#send">
                <i class="bi bi-send" aria-hidden="true"></i> Send now
            </a>
            <a class="btn btn-outline-secondary" href="<?= e(url("/documents/$id")) ?>">
                <i class="bi bi-eye" aria-hidden="true"></i> View document
            </a>
        </div>
        <p class="mt-4 mb-0 small"><a href="<?= e(url('/documents/create')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Register another document</a></p>
    </div>
</div>
