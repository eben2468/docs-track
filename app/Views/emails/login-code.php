<?php
/**
 * One-time sign-in code email.
 *
 * @var string $name
 * @var string $username
 * @var string $code
 * @var int $minutes
 * @var string $ip
 */
$color = setting('primary_color');
?>
<p>Hello <?= e($name) ?>,</p>
<p>Use this code to sign in to the <?= e(setting('app_name')) ?> as <strong><?= e($username) ?></strong>:</p>
<p style="margin:24px 0;text-align:center;">
    <span style="display:inline-block;font-family:Consolas,'Courier New',monospace;font-size:28px;font-weight:bold;letter-spacing:4px;color:<?= e($color) ?>;background:#f1f4f9;border:1px solid #d7deea;border-radius:8px;padding:14px 22px;user-select:all;"><?= e($code) ?></span>
</p>
<p>Copy the code exactly as shown (it is case-sensitive) and paste it on the sign-in page.
    It expires in <?= e($minutes) ?> minutes and can be used once.</p>
<p style="font-size:13px;color:#6c757d;">
    Requested <?= e(now()->format('l j F Y, H:i T')) ?> from IP address <?= e($ip) ?>.
    If this wasn't you, ignore this email: nobody can sign in without the code. Let the system
    administrator know if you keep getting these.
</p>
