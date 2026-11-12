<?php
/**
 * New account email: the username and how passwordless sign-in works.
 *
 * @var string $name
 * @var string $username
 * @var string $link Sign-in page URL
 */
$color = setting('primary_color');
?>
<p>Hello <?= e($name) ?>,</p>
<p>An account has been created for you on the <?= e(setting('app_name')) ?>.</p>
<p style="margin:20px 0;">Your username is
    <strong style="font-family:Consolas,'Courier New',monospace;font-size:16px;"><?= e($username) ?></strong></p>
<p>There is no password. To sign in, enter your username on the sign-in page and we'll email you a
    one-time code. Copy the code into the sign-in page to continue.</p>
<p style="margin:24px 0;">
    <a href="<?= e($link) ?>" style="background:<?= e($color) ?>;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;display:inline-block;">
        Go to the sign-in page
    </a>
</p>
<p style="font-size:12px;color:#6c757d;word-break:break-all;">If the button does not work, copy this address into your browser:<br><?= e($link) ?></p>
