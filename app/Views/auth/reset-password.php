<?php
/**
 * Choose a new password from a reset link.
 *
 * @var string $token
 */
?>
<h1>Set a new password</h1>
<p class="auth-subtitle">Choose a password you have not used before. At least 8 characters, including a letter and a number.</p>

<form method="post" action="<?= e(url('/reset-password/' . $token)) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="password" class="form-label">New password</label>
        <div class="position-relative">
            <input type="password" class="form-control ps-5 pe-5<?= invalid('password') ?>" id="password" name="password"
                   autocomplete="new-password" autofocus required maxlength="255">
            <i class="bi bi-lock input-icon" aria-hidden="true"></i>
            <button type="button" class="btn btn-link input-action" data-toggle-password="password" aria-label="Show password">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
            <?= feedback('password') ?>
        </div>
    </div>
    <div class="mb-4">
        <label for="password_confirmation" class="form-label">Confirm new password</label>
        <div class="position-relative">
            <input type="password" class="form-control ps-5" id="password_confirmation" name="password_confirmation"
                   autocomplete="new-password" required maxlength="255">
            <i class="bi bi-shield-check input-icon" aria-hidden="true"></i>
        </div>
    </div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2" aria-hidden="true"></i> Set password</button>
</form>
