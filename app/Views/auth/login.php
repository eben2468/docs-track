<?php
/** Sign-in form: email or staff ID + password. */
?>
<h1>Welcome back</h1>
<p class="auth-subtitle">Sign in with your VVU email address or staff ID.</p>

<form method="post" action="<?= e(url('/login')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="login" class="form-label">Email or staff ID</label>
        <div class="position-relative">
            <input type="text" class="form-control ps-5<?= invalid('login') ?>" id="login" name="login"
                   value="<?= e(old('login')) ?>" autocomplete="username" autofocus required maxlength="150"
                   placeholder="name@vvu.edu.gh or VVU-0001">
            <i class="bi bi-person input-icon" aria-hidden="true"></i>
            <?= feedback('login') ?>
        </div>
    </div>
    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-baseline">
            <label for="password" class="form-label">Password</label>
            <a class="small fw-semibold" href="<?= e(url('/forgot-password')) ?>">Forgot password?</a>
        </div>
        <div class="position-relative">
            <input type="password" class="form-control ps-5 pe-5<?= invalid('password') ?>" id="password" name="password"
                   autocomplete="current-password" required maxlength="255" placeholder="••••••••">
            <i class="bi bi-lock input-icon" aria-hidden="true"></i>
            <button type="button" class="btn btn-link input-action" data-toggle-password="password" aria-label="Show password">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
            <?= feedback('password') ?>
        </div>
    </div>
    <button type="submit" class="btn btn-primary w-100">
        Sign in <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </button>
</form>
