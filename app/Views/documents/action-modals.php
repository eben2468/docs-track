<?php
/**
 * Modals for the document actions (send/forward, receive, return, recall). Rendered by show.php.
 *
 * @var array<string, mixed> $document
 * @var array<string, bool> $can
 * @var array<string, mixed>|null $openMovement
 * @var array<string, mixed>|null $cameFromDepartment
 * @var list<array<string, mixed>> $destinations
 * @var string|null $defaultDueDate
 */
$id = (int) $document['id'];
$isForward = $document['status'] === 'received';
?>
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
