<?php
/**
 * Sign-in step 1: username. A one-time code is then emailed to the user.
 *
 * @var string $username Username from an earlier attempt (for "use a different username")
 */
?>
<h1>Welcome back</h1>
<p class="auth-subtitle">Enter your username and we'll email you a one-time sign-in code.</p>

<form method="post" action="<?= e(url('/login')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-4">
        <label for="username" class="form-label">Username</label>
        <div class="position-relative">
            <input type="text" class="form-control ps-5<?= invalid('username') ?>" id="username" name="username"
                   value="<?= e(old('username', $username)) ?>" autocomplete="username" autocapitalize="none"
                   spellcheck="false" autofocus required maxlength="50" placeholder="e.g. ama.mensah">
            <i class="bi bi-person input-icon" aria-hidden="true"></i>
            <?= feedback('username') ?>
        </div>
    </div>
    <button type="submit" class="btn btn-primary w-100">
        Send sign-in code <i class="bi bi-envelope-arrow-up" aria-hidden="true"></i>
    </button>
</form>
<p class="small text-body-secondary mt-3 mb-0">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    Accounts are created by the system administrator. Contact them if you don't have a username.
</p>
