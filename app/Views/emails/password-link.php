<?php
/**
 * Password reset / account welcome email.
 *
 * @var string $name
 * @var string $link
 * @var string $kind "reset" or "welcome"
 * @var int $minutes
 */
$color = setting('primary_color');
?>
<p>Hello <?= e($name) ?>,</p>
<?php if ($kind === 'welcome'): ?>
    <p>An account has been created for you on the <?= e(setting('app_name')) ?>. Click the button below to choose your password.</p>
<?php else: ?>
    <p>We received a request to reset your password. Click the button below to choose a new one.</p>
<?php endif; ?>
<p style="margin:24px 0;">
    <a href="<?= e($link) ?>" style="background:<?= e($color) ?>;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;display:inline-block;">
        <?= $kind === 'welcome' ? 'Set my password' : 'Reset my password' ?>
    </a>
</p>
<p>This link expires in <?= e($minutes) ?> minutes and can be used once. If it has expired, use
    "Forgot password" on the sign-in page to get a new one.</p>
<?php if ($kind !== 'welcome'): ?>
    <p>If you did not ask for this, you can ignore this email; your password will not change.</p>
<?php endif; ?>
<p style="font-size:12px;color:#6c757d;word-break:break-all;">If the button does not work, copy this address into your browser:<br><?= e($link) ?></p>
