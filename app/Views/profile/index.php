<?php
/**
 * The signed-in user's profile and how they sign in.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed>|null $department
 */
?>
<h1 class="h4 mb-4">My profile</h1>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-body"><h2 class="h6 mb-0">Personal details</h2></div>
            <div class="card-body">
                <dl class="row small mb-4">
                    <dt class="col-sm-4">Staff ID</dt><dd class="col-sm-8"><?= e($user['staff_id']) ?></dd>
                    <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($user['email']) ?></dd>
                    <dt class="col-sm-4">Department</dt><dd class="col-sm-8"><?= e($department['name'] ?? '—') ?></dd>
                    <dt class="col-sm-4">Role</dt><dd class="col-sm-8 mb-0"><?= e(role_label($user['role'])) ?></dd>
                </dl>
                <form method="post" action="<?= e(url('/profile')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full name</label>
                        <input type="text" class="form-control<?= invalid('full_name') ?>" id="full_name" name="full_name"
                               value="<?= e(old('full_name', $user['full_name'])) ?>" required maxlength="150">
                        <?= feedback('full_name') ?>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="tel" class="form-control<?= invalid('phone') ?>" id="phone" name="phone"
                               value="<?= e(old('phone', $user['phone'])) ?>" maxlength="20">
                        <?= feedback('phone') ?>
                    </div>
                    <p class="form-text">To change your username, email, staff ID or department, contact the system administrator.</p>
                    <button type="submit" class="btn btn-primary">Save details</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-body"><h2 class="h6 mb-0">How you sign in</h2></div>
            <div class="card-body small">
                <p class="mb-2">Username</p>
                <p class="fs-5 fw-semibold font-monospace mb-3"><?= e($user['username']) ?></p>
                <p class="mb-2">
                    <i class="bi bi-envelope-check text-primary" aria-hidden="true"></i>
                    Each time you sign in, a one-time code is emailed to <strong><?= e($user['email']) ?></strong>.
                    There is no password to remember.
                </p>
                <p class="text-body-secondary mb-0">Never share your sign-in codes. System staff will never ask for them.</p>
            </div>
        </div>
    </div>
</div>
