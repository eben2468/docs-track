<?php
/**
 * HTML email wrapper. Inline styles and tables only: email clients ignore external CSS.
 * The logo is referenced as "cid:<SmtpTransport::LOGO_CID>" and embedded in the message by
 * SmtpTransport, so it shows without loading remote images (and works from localhost).
 *
 * @var string $content
 * @var string $subject
 */
$appName = setting('app_name');
$color = setting('primary_color');
$hasLogo = setting('logo_path') !== '';
$font = "'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($subject ?? $appName) ?></title>
</head>
<body style="margin:0;padding:0;background:#eef1f6;font-family:<?= $font ?>;color:#1f2933;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef1f6;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="580" cellspacing="0" cellpadding="0" style="max-width:580px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(15,35,70,.08);">
                <tr>
                    <td style="background-color:<?= e($color) ?>;background-image:linear-gradient(135deg,<?= e($color) ?> 0%,#06264a 100%);padding:22px 28px;">
                        <table role="presentation" cellspacing="0" cellpadding="0">
                            <tr>
                                <?php if ($hasLogo): ?>
                                    <td style="padding-right:14px;vertical-align:middle;">
                                        <table role="presentation" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="background:#ffffff;border-radius:12px;padding:6px;line-height:0;">
                                                    <img src="cid:<?= e(\App\Services\Mail\SmtpTransport::LOGO_CID) ?>" width="52" height="52" alt="VVU"
                                                         style="display:block;width:52px;height:52px;border:0;outline:none;">
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                <?php endif; ?>
                                <td style="vertical-align:middle;">
                                    <div style="font-family:<?= $font ?>;font-size:19px;font-weight:700;color:#ffffff;line-height:1.25;letter-spacing:.2px;">Valley View University</div>
                                    <div style="font-family:<?= $font ?>;font-size:13px;color:#cfdcf0;line-height:1.4;margin-top:2px;"><?= e($appName) ?></div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="background:#f2b632;height:4px;line-height:4px;font-size:0;">&nbsp;</td>
                </tr>
                <tr>
                    <td style="padding:28px;font-size:15px;line-height:1.65;">
                        <?= $content ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px;background:#f8f9fb;font-size:12px;line-height:1.5;color:#6b7785;border-top:1px solid #e6e9ef;">
                        <strong style="color:#47525e;">Valley View University</strong> &middot; <?= e($appName) ?><br>
                        This is an automated message; please do not reply.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
