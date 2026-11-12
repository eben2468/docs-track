<?php
/**
 * Choose a new password from a reset link.
 *
 * @var string $token
 */
?>
<form method="post" action="<?= e(url('/reset-password/' . $token)) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="password" class="form-label">New password</label>
        <input type="password" class="form-control<?= invalid('password') ?>" id="password" name="password"
               autocomplete="new-password" autofocus required maxlength="255" aria-describedby="passwordHelp">
        <div id="passwordHelp" class="form-text">At least 8 characters, including a letter and a number.</div>
        <?= feedback('password') ?>
    </div>
    <div class="mb-3">
        <label for="password_confirmation" class="form-label">Confirm new password</label>
        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
               autocomplete="new-password" required maxlength="255">
    </div>
    <button type="submit" class="btn btn-primary w-100">Set password</button>
</form>
