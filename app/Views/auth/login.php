<?php
/** Sign-in form: email or staff ID + password. */
?>
<form method="post" action="<?= e(url('/login')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="login" class="form-label">Email or staff ID</label>
        <input type="text" class="form-control<?= invalid('login') ?>" id="login" name="login"
               value="<?= e(old('login')) ?>" autocomplete="username" autofocus required maxlength="150">
        <?= feedback('login') ?>
    </div>
    <div class="mb-3">
        <div class="d-flex justify-content-between">
            <label for="password" class="form-label">Password</label>
            <a class="small" href="<?= e(url('/forgot-password')) ?>">Forgot password?</a>
        </div>
        <input type="password" class="form-control<?= invalid('password') ?>" id="password" name="password"
               autocomplete="current-password" required maxlength="255">
        <?= feedback('password') ?>
    </div>
    <button type="submit" class="btn btn-primary w-100">
        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Sign in
    </button>
</form>
