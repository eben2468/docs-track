<?php
/**
 * Document detail (FR-8): where it is and for how long, available actions, metadata,
 * attachments, the departments visited and the movement timeline.
 * Two columns on desktop (details + actions left, timeline right), stacked on mobile.
 *
 * @var array<string, mixed> $document
 * @var list<array<string, mixed>> $attachments
 * @var list<array<string, mixed>> $history Movements, newest first
 * @var array<string, mixed>|null $openMovement The movement awaiting receipt (with names)
 * @var array<string, mixed>|null $cameFromDepartment
 * @var array<string, bool> $can Allowed actions for the current user
 * @var list<array<string, mixed>> $destinations
 * @var string|null $defaultDueDate
 * @var bool $viaQr Opened by scanning the QR label
 * @var int $alertHours
 * @var int $maxUploadMb
 */
$id = (int) $document['id'];
$formatLabels = ['physical' => 'Physical', 'digital' => 'Digital', 'both' => 'Physical & digital'];
$humanSize = static function (int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
};
$isForward = $document['status'] === 'received';
$hasActions = $can['send'] || $can['receive'] || $can['return'] || $can['recall'] || $can['complete'] || $can['cancel'];

$current = null;
foreach ($history as $m) {
    if ($m['status'] === 'received') {
        $current = $m;
        break;
    }
}
$today = now()->format('Y-m-d');
$overdue = $document['status'] === 'received' && $current !== null && $current['due_date'] !== null && $current['due_date'] < $today;
$stuck = $openMovement !== null && seconds_since($openMovement['sent_at']) > $alertHours * 3600;
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/documents')) ?>">Documents</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($document['tracking_no']) ?></li>
    </ol>
</nav>

<?php if ($viaQr && $can['receive']): ?>
    <div class="qr-receive card border-success shadow-sm mb-4">
        <div class="card-body text-center p-4">
            <p class="mb-1 text-success-emphasis"><i class="bi bi-qr-code-scan" aria-hidden="true"></i> Scanned label</p>
            <h2 class="h5 mb-3"><?= e($document['tracking_no']) ?> is on its way to your department</h2>
            <form method="post" action="<?= e(url("/documents/$id/receive")) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="method" value="qr">
                <label for="qr_receipt_note" class="visually-hidden">Receipt note</label>
                <input type="text" class="form-control mb-3 mx-auto qr-note" id="qr_receipt_note" name="receipt_note" maxlength="500"
                       placeholder="Receipt note (optional)">
                <button type="submit" class="btn btn-success btn-lg w-100 qr-receive-button">
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receive this document
                </button>
            </form>
        </div>
    </div>
<?php elseif ($viaQr && $openMovement !== null && !$can['receive']): ?>
    <div class="alert alert-info" role="status">
        <i class="bi bi-qr-code-scan" aria-hidden="true"></i>
        This document is on its way to <strong><?= e($openMovement['to_department_name']) ?></strong>, not your department, so you cannot receive it.
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
    <div class="min-w-0">
        <p class="font-monospace text-body-secondary mb-1"><?= e($document['tracking_no']) ?></p>
        <h1 class="h4 mb-2 text-break"><?= e($document['title']) ?></h1>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?= partial('partials/status-badge', ['status' => $document['status']]) ?>
            <?php if ($overdue): ?><span class="badge text-bg-danger"><i class="bi bi-alarm" aria-hidden="true"></i> Overdue</span><?php endif; ?>
            <?php if ($stuck): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Not yet received</span><?php endif; ?>
            <?php if ($document['priority'] === 'urgent'): ?>
                <span class="badge text-bg-danger"><i class="bi bi-lightning-charge" aria-hidden="true"></i> Urgent</span>
            <?php endif; ?>
            <?php if ($document['confidentiality'] === 'confidential'): ?>
                <span class="badge text-bg-dark"><i class="bi bi-lock" aria-hidden="true"></i> Confidential</span>
            <?php endif; ?>
        </div>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url("/documents/$id/label")) ?>" target="_blank" rel="noopener">
        <i class="bi bi-qr-code" aria-hidden="true"></i> QR label
    </a>
</div>

<div class="location-card card shadow-sm mb-4 <?= $overdue ? 'border-danger' : ($openMovement ? 'border-warning' : '') ?>">
    <div class="card-body d-flex gap-3 align-items-start">
        <?php if ($document['status'] === 'in_transit' && $openMovement !== null): ?>
            <i class="bi bi-truck fs-3 text-warning" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">In transit to <?= e($openMovement['to_department_name']) ?><?php if ($openMovement['to_user_name']): ?> (for <?= e($openMovement['to_user_name']) ?>)<?php endif; ?></div>
                <div class="small text-body-secondary">
                    Sent by <?= e($openMovement['from_user_name']) ?> (<?= e($openMovement['from_department_name']) ?>) — awaiting receipt for
                    <strong class="<?= $stuck ? 'text-warning-emphasis' : '' ?>"><?= e(human_duration(seconds_since($openMovement['sent_at']))) ?></strong>
                    <?php if ($openMovement['due_date']): ?> · due <?= e(format_datetime($openMovement['due_date'], 'j M Y')) ?><?php endif; ?>
                </div>
            </div>
        <?php elseif ($document['status'] === 'completed'): ?>
            <i class="bi bi-check-circle-fill fs-3 text-success" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">Completed at <?= e($document['current_department_name']) ?></div>
                <div class="small text-body-secondary">By <?= e($document['completed_by_name'] ?? '—') ?>, <?= time_ago($document['completed_at']) ?></div>
            </div>
        <?php elseif ($document['status'] === 'cancelled'): ?>
            <i class="bi bi-x-circle-fill fs-3 text-secondary" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">Cancelled</div>
                <div class="small text-body-secondary">This document was voided before it was sent.</div>
            </div>
        <?php else:
            $since = $current['received_at'] ?? $document['created_at']; ?>
            <i class="bi bi-geo-alt-fill fs-3 <?= $overdue ? 'text-danger' : 'text-primary' ?>" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">
                    Currently at: <?= e($document['current_department_name']) ?>
                    <?php if ($document['current_holder_name']): ?>— held by <?= e($document['current_holder_name']) ?><?php endif; ?>
                    — for <?= e(human_duration(seconds_since($since))) ?>
                </div>
                <div class="small text-body-secondary">
                    <?php if ($current !== null): ?>
                        Received <?= time_ago($current['received_at']) ?> from <?= e($current['from_department_name']) ?>
                        <?php if ($current['due_date']): ?>· <span class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>">due <?= e(format_datetime($current['due_date'], 'j M Y')) ?><?= $overdue ? ' (overdue)' : '' ?></span><?php endif; ?>
                    <?php else: ?>
                        Draft — registered <?= time_ago($document['created_at']) ?>, not sent yet.
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <?php if ($hasActions): ?>
            <div class="card shadow-sm mb-4" id="send">
                <div class="card-header bg-body"><h2 class="h6 mb-0">Actions</h2></div>
                <div class="card-body d-grid gap-2">
                    <?php if ($can['receive']): ?>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#receiveModal">
                            <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receive
                        </button>
                    <?php endif; ?>
                    <?php if ($can['send']): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#sendModal">
                            <i class="bi bi-send" aria-hidden="true"></i> <?= $isForward ? 'Forward' : 'Send' ?>
                        </button>
                    <?php endif; ?>
                    <?php if ($can['return']): ?>
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#returnModal">
                            <i class="bi bi-arrow-return-left" aria-hidden="true"></i> Return to <?= e($cameFromDepartment['code'] ?? 'sender') ?>
                        </button>
                    <?php endif; ?>
                    <?php if ($can['complete']): ?>
                        <form method="post" action="<?= e(url("/documents/$id/complete")) ?>" class="d-grid"
                              data-confirm="Mark <?= e($document['tracking_no']) ?> as completed? It will be closed and can no longer be sent, returned or changed."
                              data-confirm-button="Mark completed" data-confirm-variant="success">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-success"><i class="bi bi-check2-circle" aria-hidden="true"></i> Complete</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($can['recall']): ?>
                        <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#recallModal">
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Recall
                        </button>
                    <?php endif; ?>
                    <?php if ($can['cancel']): ?>
                        <form method="post" action="<?= e(url("/documents/$id/cancel")) ?>" class="d-grid"
                              data-confirm="Cancel <?= e($document['tracking_no']) ?>? Use this only for a document registered in error. It cannot be undone; the record is kept."
                              data-confirm-button="Cancel document" data-confirm-variant="danger">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-circle" aria-hidden="true"></i> Cancel draft</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-body"><h2 class="h6 mb-0">Details</h2></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Document type</dt><dd class="col-7"><?= e($document['type_name']) ?></dd>
                    <dt class="col-5">Reference no.</dt><dd class="col-7 text-break"><?= e($document['reference_no'] ?? '—') ?></dd>
                    <dt class="col-5">Format</dt><dd class="col-7"><?= e($formatLabels[$document['format']] ?? $document['format']) ?></dd>
                    <dt class="col-5">Pages</dt><dd class="col-7"><?= e($document['pages'] ?? '—') ?></dd>
                    <dt class="col-5">Priority</dt><dd class="col-7"><?= e(ucfirst((string) $document['priority'])) ?></dd>
                    <dt class="col-5">Origin</dt><dd class="col-7"><?= e($document['origin_department_name']) ?></dd>
                    <dt class="col-5">Registered by</dt>
                    <dd class="col-7"><?= e($document['created_by_name']) ?><br><span class="text-body-secondary"><?= time_ago($document['created_at']) ?></span></dd>
                </dl>
                <?php if ($document['description']): ?>
                    <hr>
                    <h3 class="h6">Description</h3>
                    <p class="mb-0 small text-prewrap"><?= e($document['description']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4" id="attachments">
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
                        };
                        $hidden = (int) $a['is_hidden'] === 1; ?>
                        <li class="list-group-item d-flex align-items-center gap-2<?= $hidden ? ' bg-body-tertiary' : '' ?>">
                            <i class="bi bi-<?= $icon ?> fs-5 text-body-secondary" aria-hidden="true"></i>
                            <div class="flex-grow-1 min-w-0">
                                <a class="text-break" href="<?= e(url("/attachments/{$a['id']}/download")) ?>"><?= e($a['original_name']) ?></a>
                                <div class="small text-body-secondary">
                                    <?= e($humanSize((int) $a['size_bytes'])) ?> · <?= e($a['uploaded_by_name']) ?> · <?= e(format_datetime($a['created_at'], 'j M Y')) ?>
                                    <?php if ($hidden): ?><span class="badge text-bg-secondary ms-1">Hidden</span><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($can['manageHidden']): ?>
                                <form method="post" action="<?= e(url("/attachments/{$a['id']}/visibility")) ?>"
                                      data-confirm="<?= $hidden ? 'Make this attachment visible again?' : 'Hide this attachment from everyone except administrators? The file is kept.' ?>"
                                      data-confirm-button="<?= $hidden ? 'Unhide' : 'Hide' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="hidden" value="<?= $hidden ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= $hidden ? 'Unhide' : 'Hide' ?>">
                                        <i class="bi <?= $hidden ? 'bi-eye' : 'bi-eye-slash' ?>" aria-hidden="true"></i>
                                        <span class="visually-hidden"><?= $hidden ? 'Unhide' : 'Hide' ?> <?= e($a['original_name']) ?></span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($can['edit']): ?>
                <div class="card-footer bg-body">
                    <form method="post" action="<?= e(url("/documents/$id/attachments")) ?>" enctype="multipart/form-data" novalidate>
                        <?= csrf_field() ?>
                        <label for="attachments_input" class="form-label small mb-1">Add attachments</label>
                        <div class="input-group input-group-sm has-validation">
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

    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-body"><h2 class="h6 mb-0">Route</h2></div>
            <div class="card-body overflow-auto"><?= partial('partials/progress-strip', ['document' => $document, 'history' => $history]) ?></div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header bg-body d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Movement timeline</h2>
                <span class="badge text-bg-light border"><?= count($history) ?> movement<?= count($history) === 1 ? '' : 's' ?></span>
            </div>
            <div class="card-body">
                <?= partial('partials/timeline', ['document' => $document, 'history' => $history, 'alertHours' => $alertHours]) ?>
            </div>
        </div>
    </div>
</div>

<?= partial('documents/action-modals', [
    'document' => $document,
    'can' => $can,
    'openMovement' => $openMovement,
    'cameFromDepartment' => $cameFromDepartment,
    'destinations' => $destinations,
    'defaultDueDate' => $defaultDueDate,
]) ?>
