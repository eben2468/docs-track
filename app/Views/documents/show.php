<?php
/**
 * Document detail (FR-8): metadata, available actions, movement history and attachments.
 *
 * @var array<string, mixed> $document
 * @var list<array<string, mixed>> $attachments
 * @var list<array<string, mixed>> $history Movements, newest first
 * @var array<string, mixed>|null $openMovement The movement awaiting receipt (with names)
 * @var array<string, mixed>|null $cameFrom The movement that brought the document to its holder
 * @var array<string, mixed>|null $cameFromDepartment
 * @var array<string, bool> $can Allowed actions for the current user
 * @var list<array<string, mixed>> $destinations Departments it can be sent to
 * @var string|null $defaultDueDate
 * @var int $maxUploadMb
 */
$id = (int) $document['id'];
$formatLabels = ['physical' => 'Physical', 'digital' => 'Digital', 'both' => 'Physical & digital'];
$actionLabels = ['send' => 'Sent', 'forward' => 'Forwarded', 'return' => 'Returned'];
$humanSize = static function (int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
};
$isForward = $document['status'] === 'received';
$hasActions = $can['send'] || $can['receive'] || $can['return'] || $can['recall'] || $can['complete'] || $can['cancel'];
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($document['tracking_no']) ?></li>
    </ol>
</nav>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
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
    <a class="btn btn-outline-secondary" href="<?= e(url("/documents/$id/label")) ?>" target="_blank" rel="noopener">
        <i class="bi bi-qr-code" aria-hidden="true"></i> QR label
    </a>
</div>

<?php if ($openMovement !== null): ?>
    <div class="alert alert-warning d-flex gap-3 align-items-start" role="status">
        <i class="bi bi-truck fs-4" aria-hidden="true"></i>
        <div>
            <strong>In transit to <?= e($openMovement['to_department_name']) ?></strong>
            <?php if ($openMovement['to_user_name']): ?>(for <?= e($openMovement['to_user_name']) ?>)<?php endif; ?>,
            awaiting receipt.
            <div class="small">
                <?= e($actionLabels[$openMovement['action']]) ?> by <?= e($openMovement['from_user_name']) ?> (<?= e($openMovement['from_department_name']) ?>)
                on <?= e(format_datetime($openMovement['sent_at'])) ?><?php if ($openMovement['due_date']): ?> · due <?= e(format_datetime($openMovement['due_date'], 'j M Y')) ?><?php endif; ?>
            </div>
            <div class="small fst-italic">“<?= e($openMovement['note']) ?>”</div>
        </div>
    </div>
<?php endif; ?>

<?php if ($hasActions): ?>
    <div class="card shadow-sm mb-4" id="send">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold me-2">Actions</span>
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
                    <i class="bi bi-arrow-return-left" aria-hidden="true"></i> Return
                </button>
            <?php endif; ?>
            <?php if ($can['complete']): ?>
                <form method="post" action="<?= e(url("/documents/$id/complete")) ?>"
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
                <form method="post" action="<?= e(url("/documents/$id/cancel")) ?>"
                      data-confirm="Cancel <?= e($document['tracking_no']) ?>? Use this only for a document registered in error. It cannot be undone; the record is kept."
                      data-confirm-button="Cancel document" data-confirm-variant="danger">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-circle" aria-hidden="true"></i> Cancel draft</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

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
                    <dt class="col-sm-4"><?= $document['status'] === 'in_transit' ? 'Last held by' : 'Currently with' ?></dt>
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

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-body d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Movement history</h2>
                <span class="badge text-bg-light border"><?= count($history) ?></span>
            </div>
            <?php if ($history === []): ?>
                <div class="card-body small text-body-secondary">Not sent anywhere yet.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($history as $m): ?>
                        <li class="list-group-item">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <span class="fw-semibold"><?= e($actionLabels[$m['action']]) ?></span>
                                    <?= e($m['from_department_code']) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i><span class="visually-hidden">to</span> <?= e($m['to_department_code']) ?>
                                    <?php if ($m['to_user_name']): ?><span class="text-body-secondary">(for <?= e($m['to_user_name']) ?>)</span><?php endif; ?>
                                </div>
                                <?php if ($m['status'] === 'in_transit'): ?>
                                    <span class="badge text-bg-warning">Awaiting receipt</span>
                                <?php elseif ($m['status'] === 'received'): ?>
                                    <span class="badge text-bg-primary">Received</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Recalled</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-body-secondary">
                                By <?= e($m['from_user_name']) ?>, <?= e(format_datetime($m['sent_at'])) ?><?php if ($m['due_date']): ?> · due <?= e(format_datetime($m['due_date'], 'j M Y')) ?><?php endif; ?>
                            </div>
                            <div class="fst-italic">“<?= e($m['note']) ?>”</div>
                            <?php if ($m['status'] === 'received'): ?>
                                <div class="text-success-emphasis">
                                    Received by <?= e($m['received_by_name']) ?>, <?= e(format_datetime($m['received_at'])) ?> (<?= e($m['receive_method']) ?>)<?php if ($m['receipt_note']): ?>: “<?= e($m['receipt_note']) ?>”<?php endif; ?>
                                </div>
                            <?php elseif ($m['status'] === 'recalled'): ?>
                                <div class="text-secondary-emphasis">
                                    Recalled by <?= e($m['recalled_by_name']) ?>, <?= e(format_datetime($m['recalled_at'])) ?>: “<?= e($m['recall_reason']) ?>”
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
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
</div>

<?php if ($can['send']): ?>
    <div class="modal fade" id="sendModal" tabindex="-1" aria-labelledby="sendModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?= e(url("/documents/$id/send")) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="sendModalTitle"><?= $isForward ? 'Forward' : 'Send' ?> <?= e($document['tracking_no']) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="to_department_id" class="form-label">Destination department <span class="text-danger">*</span></label>
                        <input type="search" class="form-control form-control-sm mb-1" placeholder="Type to filter departments…"
                               data-filter-select="to_department_id" aria-label="Filter departments">
                        <select class="form-select<?= invalid('to_department_id') ?>" id="to_department_id" name="to_department_id" required
                                size="6" data-recipients-target="to_user_id" data-recipients-url="<?= e(url('/api/departments/')) ?>">
                            <?php foreach ($destinations as $d): ?>
                                <option value="<?= e($d['id']) ?>"<?= (string) old('to_department_id') === (string) $d['id'] ? ' selected' : '' ?>>
                                    <?= e($d['name']) ?> (<?= e($d['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= feedback('to_department_id') ?>
                    </div>
                    <div class="mb-3">
                        <label for="to_user_id" class="form-label">Specific recipient <span class="text-body-secondary small">(optional)</span></label>
                        <select class="form-select<?= invalid('to_user_id') ?>" id="to_user_id" name="to_user_id" disabled>
                            <option value="">Anyone in the department</option>
                        </select>
                        <div class="form-text">Without a recipient, everyone in the department is notified.</div>
                        <?= feedback('to_user_id') ?>
                    </div>
                    <div class="mb-3">
                        <label for="note" class="form-label">Action note <span class="text-danger">*</span></label>
                        <input type="text" class="form-control<?= invalid('note') ?>" id="note" name="note" list="noteSuggestions"
                               value="<?= e(old('note')) ?>" required maxlength="500" placeholder="e.g. For your approval">
                        <datalist id="noteSuggestions">
                            <option value="For your approval">
                            <option value="For your information">
                            <option value="Please treat">
                            <option value="For your signature">
                            <option value="For your comments">
                        </datalist>
                        <?= feedback('note') ?>
                    </div>
                    <div>
                        <label for="due_date" class="form-label">Due date</label>
                        <input type="date" class="form-control<?= invalid('due_date') ?>" id="due_date" name="due_date"
                               value="<?= e(old('due_date')) ?>" min="<?= e(now()->format('Y-m-d')) ?>" aria-describedby="dueHelp">
                        <div id="dueHelp" class="form-text">
                            Leave blank for the default: <?= e(format_datetime($defaultDueDate, 'D j M Y')) ?>
                            (<?= e($document['type_sla_days']) ?> working days, excluding weekends and holidays).
                        </div>
                        <?= feedback('due_date') ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send" aria-hidden="true"></i> <?= $isForward ? 'Forward' : 'Send' ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($can['receive']): ?>
    <div class="modal fade" id="receiveModal" tabindex="-1" aria-labelledby="receiveModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?= e(url("/documents/$id/receive")) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="receiveModalTitle">Receive <?= e($document['tracking_no']) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Confirm that your department now has this document. You will be recorded as its holder.</p>
                    <label for="receipt_note" class="form-label">Receipt note <span class="text-body-secondary small">(optional)</span></label>
                    <input type="text" class="form-control<?= invalid('receipt_note') ?>" id="receipt_note" name="receipt_note" maxlength="500"
                           placeholder="e.g. Received with 3 attachments, 1 missing">
                    <?= feedback('receipt_note') ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receive</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($can['return']): ?>
    <div class="modal fade" id="returnModal" tabindex="-1" aria-labelledby="returnModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?= e(url("/documents/$id/return")) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="returnModalTitle">Return <?= e($document['tracking_no']) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>The document goes back to <strong><?= e($cameFromDepartment['name'] ?? '') ?></strong>, the department it came from.</p>
                    <label for="return_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control<?= invalid('reason') ?>" id="return_reason" name="reason" rows="3" maxlength="500" required
                              placeholder="e.g. Missing signature on page 2"></textarea>
                    <?= feedback('reason') ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-return-left" aria-hidden="true"></i> Return</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($can['recall']): ?>
    <div class="modal fade" id="recallModal" tabindex="-1" aria-labelledby="recallModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?= e(url("/documents/$id/recall")) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="recallModalTitle">Recall <?= e($document['tracking_no']) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Cancel the send to <strong><?= e($openMovement['to_department_name'] ?? '') ?></strong>. The document goes back to its previous state with your department.</p>
                    <label for="recall_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control<?= invalid('reason') ?>" id="recall_reason" name="reason" rows="3" maxlength="500" required
                              placeholder="e.g. Sent to the wrong department"></textarea>
                    <?= feedback('reason') ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Recall</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
