<?php
/**
 * HTML email wrapper. Inline styles only: email clients ignore external CSS.
 *
 * @var string $content
 * @var string $subject
 */
$appName = setting('app_name');
$color = setting('primary_color');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($subject ?? $appName) ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Segoe UI,Arial,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f9;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
                <tr>
                    <td style="background:<?= e($color) ?>;color:#ffffff;padding:16px 24px;font-size:16px;font-weight:600;">
                        <?= e($appName) ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;font-size:15px;line-height:1.6;">
                        <?= $content ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px;font-size:12px;color:#6c757d;border-top:1px solid #e9ecef;">
                        Valley View University &middot; This is an automated message; please do not reply.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
