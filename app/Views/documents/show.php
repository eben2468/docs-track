<?php
/**
 * Document detail (FR-8, first part): metadata, attachments and the actions available
 * in this release. The movement timeline and send/receive actions are added with the
 * movement engine.
 *
 * @var array<string, mixed> $document
 * @var list<array<string, mixed>> $attachments
 * @var bool $canEdit
 * @var bool $canCancel
 * @var bool $canManageHidden
 * @var int $maxUploadMb
 */
$id = (int) $document['id'];
$formatLabels = ['physical' => 'Physical', 'digital' => 'Digital', 'both' => 'Physical & digital'];
$humanSize = static function (int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
};
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($document['tracking_no']) ?></li>
    </ol>
</nav>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <p class="font-monospace text-body-secondary mb-1"><?= e($document['tracking_no']) ?></p>
        <h1 class="h4 mb-2"><?= e($document['title']) ?></h1>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?= partial('partials/status-badge', ['status' => $document['status']]) ?>
            <?php if ($document['priority'] === 'urgent'): ?>
                <span class="badge text-bg-danger"><i class="bi bi-lightning-charge" aria-hidden="true"></i> Urgent</span>
            <?php endif; ?>
            <?php if ($document['confidentiality'] === 'confidential'): ?>
                <span class="badge text-bg-dark"><i class="bi bi-lock" aria-hidden="true"></i> Confidential</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url("/documents/$id/label")) ?>" target="_blank" rel="noopener">
            <i class="bi bi-qr-code" aria-hidden="true"></i> QR label
        </a>
        <?php if ($canCancel): ?>
            <form method="post" action="<?= e(url("/documents/$id/cancel")) ?>"
                  data-confirm="Cancel <?= e($document['tracking_no']) ?>? Use this only for a document registered in error. It cannot be undone; the record is kept."
                  data-confirm-button="Cancel document" data-confirm-variant="danger">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-circle" aria-hidden="true"></i> Cancel draft</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-body"><h2 class="h6 mb-0">Details</h2></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4">Document type</dt><dd class="col-sm-8"><?= e($document['type_name']) ?></dd>
                    <dt class="col-sm-4">Reference no.</dt><dd class="col-sm-8"><?= e($document['reference_no'] ?? '—') ?></dd>
                    <dt class="col-sm-4">Format</dt><dd class="col-sm-8"><?= e($formatLabels[$document['format']] ?? $document['format']) ?></dd>
                    <dt class="col-sm-4">Pages</dt><dd class="col-sm-8"><?= e($document['pages'] ?? '—') ?></dd>
                    <dt class="col-sm-4">Origin</dt><dd class="col-sm-8"><?= e($document['origin_department_name']) ?></dd>
                    <dt class="col-sm-4">Currently with</dt>
                    <dd class="col-sm-8">
                        <?= e($document['current_department_name']) ?>
                        <?php if ($document['current_holder_name']): ?><span class="text-body-secondary">(<?= e($document['current_holder_name']) ?>)</span><?php endif; ?>
                    </dd>
                    <dt class="col-sm-4">Registered by</dt>
                    <dd class="col-sm-8"><?= e($document['created_by_name']) ?>, <span title="<?= e(format_datetime($document['created_at'], 'l j F Y, H:i:s')) ?>"><?= e(format_datetime($document['created_at'])) ?></span></dd>
                    <?php if ($document['completed_at']): ?>
                        <dt class="col-sm-4">Completed</dt>
                        <dd class="col-sm-8"><?= e(format_datetime($document['completed_at'])) ?> by <?= e($document['completed_by_name'] ?? '—') ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if ($document['description']): ?>
                    <hr>
                    <h3 class="h6">Description</h3>
                    <p class="mb-0 text-prewrap"><?= e($document['description']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm" id="attachments">
            <div class="card-header bg-body d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Attachments</h2>
                <span class="badge text-bg-light border"><?= count($attachments) ?></span>
            </div>
            <?php if ($attachments === []): ?>
                <div class="card-body small text-body-secondary">No attachments.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($attachments as $a):
                        $icon = match (true) {
                            str_contains($a['mime_type'], 'pdf') => 'file-earmark-pdf',
                            str_starts_with($a['mime_type'], 'image/') => 'file-earmark-image',
                            str_contains($a['mime_type'], 'spreadsheet') => 'file-earmark-spreadsheet',
                            default => 'file-earmark-word',
                        }; ?>
                        <li class="list-group-item d-flex align-items-center gap-2<?= (int) $a['is_hidden'] === 1 ? ' bg-body-tertiary' : '' ?>">
                            <i class="bi bi-<?= $icon ?> fs-5 text-body-secondary" aria-hidden="true"></i>
                            <div class="flex-grow-1 min-w-0">
                                <a class="text-break" href="<?= e(url("/attachments/{$a['id']}/download")) ?>"><?= e($a['original_name']) ?></a>
                                <div class="small text-body-secondary">
                                    <?= e($humanSize((int) $a['size_bytes'])) ?> · <?= e($a['uploaded_by_name']) ?> · <?= e(format_datetime($a['created_at'], 'j M Y')) ?>
                                    <?php if ((int) $a['is_hidden'] === 1): ?><span class="badge text-bg-secondary ms-1">Hidden</span><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($canManageHidden): ?>
                                <form method="post" action="<?= e(url("/attachments/{$a['id']}/visibility")) ?>"
                                      data-confirm="<?= (int) $a['is_hidden'] === 1 ? 'Make this attachment visible again?' : 'Hide this attachment from everyone except administrators? The file is kept.' ?>"
                                      data-confirm-button="<?= (int) $a['is_hidden'] === 1 ? 'Unhide' : 'Hide' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="hidden" value="<?= (int) $a['is_hidden'] === 1 ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= (int) $a['is_hidden'] === 1 ? 'Unhide' : 'Hide' ?>">
                                        <i class="bi <?= (int) $a['is_hidden'] === 1 ? 'bi-eye' : 'bi-eye-slash' ?>" aria-hidden="true"></i>
                                        <span class="visually-hidden"><?= (int) $a['is_hidden'] === 1 ? 'Unhide' : 'Hide' ?> <?= e($a['original_name']) ?></span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <div class="card-footer bg-body">
                    <form method="post" action="<?= e(url("/documents/$id/attachments")) ?>" enctype="multipart/form-data" novalidate>
                        <?= csrf_field() ?>
                        <label for="attachments_input" class="form-label small mb-1">Add attachments</label>
                        <div class="input-group input-group-sm">
                            <input type="file" class="form-control<?= invalid('attachments') ?>" id="attachments_input" name="attachments[]" multiple
                                   accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx">
                            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-upload" aria-hidden="true"></i> Upload</button>
                            <?= feedback('attachments') ?>
                        </div>
                        <div class="form-text">PDF, JPG, PNG, DOCX, XLSX · up to <?= e(\App\Services\UploadService::MAX_FILES) ?> files, <?= e($maxUploadMb) ?> MB each.</div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
