<?php
/**
 * Notification email (FR-9). Confidential documents show only the tracking number.
 *
 * @var string $message
 * @var array<string, mixed>|null $document
 * @var string $type
 * @var string $link
 */
$color = setting('primary_color');
$confidential = $document !== null && $document['confidentiality'] === 'confidential';
$alert = in_array($type, ['unreceived_alert', 'overdue_alert'], true);
?>
<p>Hello,</p>
<?php if ($alert): ?>
    <p style="margin:0 0 12px;padding:10px 12px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;">
        <strong>Action needed.</strong> <?= e($message) ?>
    </p>
<?php else: ?>
    <p><?= e($message) ?></p>
<?php endif; ?>

<?php if ($document !== null): ?>
    <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin:16px 0;border:1px solid #e9ecef;border-radius:6px;font-size:14px;">
        <tr>
            <td style="padding:10px 12px;color:#6c757d;width:35%;">Tracking no.</td>
            <td style="padding:10px 12px;font-family:Consolas,monospace;font-weight:bold;"><?= e($document['tracking_no']) ?></td>
        </tr>
        <tr>
            <td style="padding:10px 12px;color:#6c757d;border-top:1px solid #e9ecef;">Title</td>
            <td style="padding:10px 12px;border-top:1px solid #e9ecef;">
                <?= $confidential ? '<em>Confidential — sign in to view</em>' : e($document['title']) ?>
            </td>
        </tr>
        <?php if ($document['priority'] === 'urgent'): ?>
            <tr>
                <td style="padding:10px 12px;color:#6c757d;border-top:1px solid #e9ecef;">Priority</td>
                <td style="padding:10px 12px;border-top:1px solid #e9ecef;color:#dc3545;font-weight:bold;">Urgent</td>
            </tr>
        <?php endif; ?>
    </table>
<?php endif; ?>

<p style="margin:24px 0;">
    <a href="<?= e($link) ?>" style="background:<?= e($color) ?>;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;display:inline-block;font-weight:600;">
        <?= $document !== null ? 'View document' : 'View notifications' ?>
    </a>
</p>
<p style="font-size:12px;color:#6c757d;">You receive these emails because of your role in your department on the <?= e(setting('app_name')) ?>.</p>
