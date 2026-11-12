<?php
/**
 * Sign-in step 2: the one-time code emailed to the user.
 *
 * @var string $username
 * @var int $minutes  How long a code stays valid
 * @var int $resendIn Seconds until "Send a new code" is allowed
 */
?>
<h1>Check your email</h1>
<p class="auth-subtitle">
    If <strong><?= e($username) ?></strong> is a registered username, we've emailed a sign-in code to the
    address on file. Copy it and paste it below. It expires in <?= e($minutes) ?> minutes.
</p>

<form method="post" action="<?= e(url('/login/verify')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-4">
        <label for="code" class="form-label">Sign-in code</label>
        <div class="position-relative">
            <input type="password" class="form-control ps-5 pe-5 otp-input<?= invalid('code') ?>" id="code" name="code"
                   autocomplete="one-time-code" autocapitalize="none" spellcheck="false" autofocus required
                   maxlength="32" placeholder="Paste your code">
            <i class="bi bi-shield-lock input-icon" aria-hidden="true"></i>
            <button type="button" class="btn btn-link input-action" data-toggle-password="code" aria-label="Show code">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
            <?= feedback('code') ?>
        </div>
        <div class="form-text">The code is case-sensitive.</div>
    </div>
    <button type="submit" class="btn btn-primary w-100">
        Sign in <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </button>
</form>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 small">
    <a class="fw-semibold" href="<?= e(url('/login')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Use a different username</a>
    <form method="post" action="<?= e(url('/login/resend')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-link btn-sm p-0 fw-semibold" data-countdown="<?= e($resendIn) ?>">
            Send a new code
        </button>
    </form>
</div>
<p class="small text-body-secondary mt-3 mb-0">
    Didn't get it? Check your spam folder and that the username is correct, or contact the system administrator.
</p>
