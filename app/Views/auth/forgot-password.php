<?php
/** Request a password reset link. */
?>
<p class="text-body-secondary small">Enter the email address on your account and we will send you a link to set a new password.</p>
<form method="post" action="<?= e(url('/forgot-password')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="email" class="form-label">Email address</label>
        <input type="email" class="form-control<?= invalid('email') ?>" id="email" name="email"
               value="<?= e(old('email')) ?>" autocomplete="email" autofocus required maxlength="150">
        <?= feedback('email') ?>
    </div>
    <button type="submit" class="btn btn-primary w-100">Send reset link</button>
</form>
<p class="text-center small mt-3 mb-0"><a href="<?= e(url('/login')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to sign in</a></p>
