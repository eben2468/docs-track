<?php
/**
 * Create / edit a holiday.
 *
 * @var array<string, mixed>|null $holiday
 */
$editing = $holiday !== null;
$action = $editing ? "/admin/holidays/{$holiday['id']}" : '/admin/holidays';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/admin/holidays')) ?>">Holidays</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= $editing ? e($holiday['name']) : 'New' ?></li>
    </ol>
</nav>
<h1 class="h4 mb-3"><?= $editing ? 'Edit holiday' : 'New holiday' ?></h1>

<div class="card shadow-sm form-narrow">
    <div class="card-body">
        <form method="post" action="<?= e(url($action)) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="holiday_date" class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control<?= invalid('holiday_date') ?>" id="holiday_date" name="holiday_date"
                       value="<?= e(old('holiday_date', $holiday['holiday_date'] ?? '')) ?>" required>
                <?= feedback('holiday_date') ?>
            </div>
            <div class="mb-3">
                <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control<?= invalid('name') ?>" id="name" name="name"
                       value="<?= e(old('name', $holiday['name'] ?? '')) ?>" required maxlength="100">
                <?= feedback('name') ?>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Add holiday' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/admin/holidays')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
