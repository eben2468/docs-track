<?php
/** Request a password reset link. */
?>
<a class="small fw-semibold d-inline-flex align-items-center gap-1 mb-4" href="<?= e(url('/login')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to sign in</a>
<h1>Forgot your password?</h1>
<p class="auth-subtitle">Enter the email address on your account and we will send you a link to choose a new one.</p>

<form method="post" action="<?= e(url('/forgot-password')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-4">
        <label for="email" class="form-label">Email address</label>
        <div class="position-relative">
            <input type="email" class="form-control ps-5<?= invalid('email') ?>" id="email" name="email"
                   value="<?= e(old('email')) ?>" autocomplete="email" autofocus required maxlength="150" placeholder="name@vvu.edu.gh">
            <i class="bi bi-envelope input-icon" aria-hidden="true"></i>
            <?= feedback('email') ?>
        </div>
    </div>
    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send" aria-hidden="true"></i> Send reset link</button>
</form>
