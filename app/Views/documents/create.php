<?php
/**
 * Register a document (FR-4).
 *
 * @var list<array<string, mixed>> $types Active document types
 * @var int $maxUploadMb
 * @var string $departmentName The user's (origin) department
 */
$value = static fn (string $field, string $default = ''): string => (string) old($field, $default);
$radio = static function (string $name, string $value, string $label, string $current, string $help = ''): string {
    $id = $name . '_' . $value;

    return '<div class="form-check">'
        . '<input class="form-check-input" type="radio" name="' . e($name) . '" id="' . e($id) . '" value="' . e($value) . '"'
        . ($current === $value ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="' . e($id) . '">' . e($label)
        . ($help !== '' ? ' <span class="small text-body-secondary">' . e($help) . '</span>' : '') . '</label></div>';
};
?>
<h1 class="h4 mb-1">Register document</h1>
<p class="text-body-secondary mb-4">The document is registered as a draft held by your department. You can print its QR label and send it next.</p>

<form method="post" action="<?= e(url('/documents/create')) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control<?= invalid('title') ?>" id="title" name="title"
                               value="<?= e($value('title')) ?>" required maxlength="255" autofocus
                               placeholder="e.g. Request for approval of conference travel">
                        <?= feedback('title') ?>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="document_type_id" class="form-label">Document type <span class="text-danger">*</span></label>
                            <select class="form-select<?= invalid('document_type_id') ?>" id="document_type_id" name="document_type_id" required>
                                <option value="">— Choose —</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= e($t['id']) ?>"<?= $value('document_type_id') === (string) $t['id'] ? ' selected' : '' ?>>
                                        <?= e($t['name']) ?> (SLA <?= e($t['sla_days']) ?> working day<?= (int) $t['sla_days'] === 1 ? '' : 's' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= feedback('document_type_id') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="reference_no" class="form-label">Reference number</label>
                            <input type="text" class="form-control<?= invalid('reference_no') ?>" id="reference_no" name="reference_no"
                                   value="<?= e($value('reference_no')) ?>" maxlength="100" placeholder="Your office's own reference, if any">
                            <?= feedback('reference_no') ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control<?= invalid('description') ?>" id="description" name="description" rows="4"
                                  maxlength="5000"><?= e($value('description')) ?></textarea>
                        <?= feedback('description') ?>
                    </div>
                    <div>
                        <label for="attachments" class="form-label">Attachments</label>
                        <input type="file" class="form-control<?= invalid('attachments') ?>" id="attachments" name="attachments[]" multiple
                               accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx" aria-describedby="attachmentsHelp">
                        <div id="attachmentsHelp" class="form-text">
                            Optional. PDF, JPG, PNG, DOCX or XLSX; up to <?= e(\App\Services\UploadService::MAX_FILES) ?> files,
                            <?= e($maxUploadMb) ?> MB each. Files must be chosen again if the form shows an error.
                        </div>
                        <?= feedback('attachments') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6">Priority <span class="text-danger">*</span></legend>
                        <?= $radio('priority', 'normal', 'Normal', $value('priority', 'normal')) ?>
                        <?= $radio('priority', 'urgent', 'Urgent', $value('priority', 'normal')) ?>
                        <?php if (error('priority')): ?><div class="text-danger small"><?= e(error('priority')) ?></div><?php endif; ?>
                    </fieldset>
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6">Confidentiality <span class="text-danger">*</span></legend>
                        <?= $radio('confidentiality', 'normal', 'Normal', $value('confidentiality', 'normal')) ?>
                        <?= $radio('confidentiality', 'confidential', 'Confidential', $value('confidentiality', 'normal'), '(title hidden on labels and public tracking)') ?>
                        <?php if (error('confidentiality')): ?><div class="text-danger small"><?= e(error('confidentiality')) ?></div><?php endif; ?>
                    </fieldset>
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6">Format <span class="text-danger">*</span></legend>
                        <?= $radio('format', 'physical', 'Physical (paper)', $value('format', 'physical')) ?>
                        <?= $radio('format', 'digital', 'Digital', $value('format', 'physical')) ?>
                        <?= $radio('format', 'both', 'Both', $value('format', 'physical')) ?>
                        <?php if (error('format')): ?><div class="text-danger small"><?= e(error('format')) ?></div><?php endif; ?>
                    </fieldset>
                    <div class="mb-3">
                        <label for="pages" class="form-label">Number of pages</label>
                        <input type="number" class="form-control<?= invalid('pages') ?>" id="pages" name="pages"
                               value="<?= e($value('pages')) ?>" min="1" max="9999">
                        <?= feedback('pages') ?>
                    </div>
                    <p class="small text-body-secondary mb-0">
                        <i class="bi bi-building" aria-hidden="true"></i>
                        Origin: <strong><?= e($departmentName) ?></strong>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Register document</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/')) ?>">Cancel</a>
    </div>
</form>
