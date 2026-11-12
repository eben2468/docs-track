<?php
/**
 * Create / edit a department.
 *
 * @var array<string, mixed>|null $department
 * @var list<array<string, mixed>> $parents Active departments (excluding this one)
 * @var list<array<string, mixed>> $members Active users of this department (edit only)
 */
$editing = $department !== null;
$action = $editing ? "/admin/departments/{$department['id']}" : '/admin/departments';
$value = static fn (string $field): string => (string) old($field, $department[$field] ?? '');
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/admin/departments')) ?>">Departments</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= $editing ? e($department['name']) : 'New' ?></li>
    </ol>
</nav>
<h1 class="h4 mb-3"><?= $editing ? 'Edit department' : 'New department' ?></h1>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" action="<?= e(url($action)) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-8">
                    <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control<?= invalid('name') ?>" id="name" name="name"
                           value="<?= e($value('name')) ?>" required maxlength="150">
                    <?= feedback('name') ?>
                </div>
                <div class="col-md-4">
                    <label for="code" class="form-label">Code <span class="text-danger">*</span></label>
                    <input type="text" class="form-control text-uppercase font-monospace<?= invalid('code') ?>" id="code" name="code"
                           value="<?= e($value('code')) ?>" required maxlength="6" pattern="[A-Za-z]{2,6}" aria-describedby="codeHelp">
                    <div id="codeHelp" class="form-text">2–6 letters, used in tracking numbers (e.g. REG).</div>
                    <?= feedback('code') ?>
                </div>
                <div class="col-md-6">
                    <label for="parent_id" class="form-label">Parent department</label>
                    <select class="form-select<?= invalid('parent_id') ?>" id="parent_id" name="parent_id">
                        <option value="">— None —</option>
                        <?php foreach ($parents as $p): ?>
                            <option value="<?= e($p['id']) ?>"<?= $value('parent_id') === (string) $p['id'] ? ' selected' : '' ?>>
                                <?= e($p['name']) ?> (<?= e($p['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= feedback('parent_id') ?>
                </div>
                <div class="col-md-6">
                    <label for="head_user_id" class="form-label">Head of department</label>
                    <?php if ($editing): ?>
                        <select class="form-select<?= invalid('head_user_id') ?>" id="head_user_id" name="head_user_id" aria-describedby="headHelp">
                            <option value="">— None —</option>
                            <?php foreach ($members as $m): ?>
                                <option value="<?= e($m['id']) ?>"<?= $value('head_user_id') === (string) $m['id'] ? ' selected' : '' ?>>
                                    <?= e($m['full_name']) ?> (<?= e(role_label($m['role'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="headHelp" class="form-text">Only active members of this department are listed. A staff member chosen here becomes a HOD.</div>
                        <?= feedback('head_user_id') ?>
                    <?php else: ?>
                        <input type="text" class="form-control" id="head_user_id" value="Set after adding users to this department" disabled>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label for="location" class="form-label">Location</label>
                    <input type="text" class="form-control<?= invalid('location') ?>" id="location" name="location"
                           value="<?= e($value('location')) ?>" maxlength="150" placeholder="e.g. Admin Block, Room 12">
                    <?= feedback('location') ?>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Department email</label>
                    <input type="email" class="form-control<?= invalid('email') ?>" id="email" name="email"
                           value="<?= e($value('email')) ?>" maxlength="150">
                    <?= feedback('email') ?>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create department' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/admin/departments')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
