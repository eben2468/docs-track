<?php
/**
 * Create / edit a document type.
 *
 * @var array<string, mixed>|null $type
 */
$editing = $type !== null;
$action = $editing ? "/admin/document-types/{$type['id']}" : '/admin/document-types';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/admin/document-types')) ?>">Document types</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= $editing ? e($type['name']) : 'New' ?></li>
    </ol>
</nav>
<h1 class="h4 mb-3"><?= $editing ? 'Edit document type' : 'New document type' ?></h1>

<div class="card shadow-sm form-narrow">
    <div class="card-body">
        <form method="post" action="<?= e(url($action)) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control<?= invalid('name') ?>" id="name" name="name"
                       value="<?= e(old('name', $type['name'] ?? '')) ?>" required maxlength="100">
                <?= feedback('name') ?>
            </div>
            <div class="mb-3">
                <label for="sla_days" class="form-label">SLA (working days) <span class="text-danger">*</span></label>
                <input type="number" class="form-control<?= invalid('sla_days') ?>" id="sla_days" name="sla_days"
                       value="<?= e(old('sla_days', $type['sla_days'] ?? setting('default_sla_days'))) ?>" min="1" max="60" required
                       aria-describedby="slaHelp">
                <div id="slaHelp" class="form-text">Working days a department has to act before the document is overdue. Weekends and holidays are not counted.</div>
                <?= feedback('sla_days') ?>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create type' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/admin/document-types')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
