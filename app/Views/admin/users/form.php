<?php
/**
 * Create / edit a user.
 *
 * @var array<string, mixed>|null $user
 * @var list<array<string, mixed>> $departments
 */
$editing = $user !== null;
$action = $editing ? "/admin/users/{$user['id']}" : '/admin/users';
$value = static fn (string $field, string $default = ''): string => (string) old($field, $user[$field] ?? $default);
$isSelf = $editing && (int) $user['id'] === (int) (auth()['id'] ?? 0);
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/admin/users')) ?>">Users</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= $editing ? e($user['full_name']) : 'New' ?></li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1 class="h4 mb-0"><?= $editing ? 'Edit user' : 'New user' ?></h1>
        <p>Users sign in with their username and a one-time code emailed to them.</p>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" action="<?= e(url($action)) ?>" novalidate autocomplete="off">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="full_name" class="form-label">Full name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control<?= invalid('full_name') ?>" id="full_name" name="full_name"
                           value="<?= e($value('full_name')) ?>" required maxlength="150">
                    <?= feedback('full_name') ?>
                </div>
                <div class="col-md-6">
                    <label for="staff_id" class="form-label">Staff ID <span class="text-danger">*</span></label>
                    <input type="text" class="form-control<?= invalid('staff_id') ?>" id="staff_id" name="staff_id"
                           value="<?= e($value('staff_id')) ?>" required maxlength="30">
                    <?= feedback('staff_id') ?>
                </div>
                <div class="col-md-6">
                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control<?= invalid('username') ?>" id="username" name="username"
                           value="<?= e($value('username')) ?>" required minlength="3" maxlength="50"
                           autocapitalize="none" spellcheck="false" aria-describedby="usernameHelp" placeholder="e.g. ama.mensah">
                    <div id="usernameHelp" class="form-text">Used to sign in. Letters, numbers, dots, hyphens and underscores.</div>
                    <?= feedback('username') ?>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control<?= invalid('email') ?>" id="email" name="email"
                           value="<?= e($value('email')) ?>" required maxlength="150" aria-describedby="emailHelp">
                    <div id="emailHelp" class="form-text">Sign-in codes are sent to this address.</div>
                    <?= feedback('email') ?>
                </div>
                <div class="col-md-6">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="tel" class="form-control<?= invalid('phone') ?>" id="phone" name="phone"
                           value="<?= e($value('phone')) ?>" maxlength="20">
                    <?= feedback('phone') ?>
                </div>
                <div class="col-md-6">
                    <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                    <select class="form-select<?= invalid('department_id') ?>" id="department_id" name="department_id" required>
                        <option value="">— Choose —</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= e($d['id']) ?>"<?= $value('department_id') === (string) $d['id'] ? ' selected' : '' ?>>
                                <?= e($d['name']) ?> (<?= e($d['code']) ?>)<?= (int) $d['is_active'] === 1 ? '' : ' — inactive' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= feedback('department_id') ?>
                </div>
                <div class="col-md-6">
                    <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                    <select class="form-select<?= invalid('role') ?>" id="role" name="role" required<?= $isSelf ? ' aria-describedby="roleHelp"' : '' ?>>
                        <?php foreach (['staff', 'hod', 'admin'] as $r): ?>
                            <option value="<?= $r ?>"<?= $value('role', 'staff') === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isSelf): ?><div id="roleHelp" class="form-text">You cannot change your own role.</div><?php endif; ?>
                    <?= feedback('role') ?>
                </div>
            </div>
            <?php if (!$editing): ?>
                <p class="form-text mt-3 mb-0"><i class="bi bi-envelope" aria-hidden="true"></i> The user will be emailed their username and how to sign in.</p>
            <?php endif; ?>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create user' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/admin/users')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
