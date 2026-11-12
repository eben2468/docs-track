<?php
/**
 * SMTP test email sent from Admin → Settings.
 *
 * @var string $transport
 * @var string $host
 * @var int|string $port
 */
?>
<p>Hello,</p>
<p>This is a test email from the <strong><?= e(setting('app_name')) ?></strong>. If you can read it, email delivery is working.</p>
<p style="font-size:13px;color:#6c757d;">
    Sent <?= e(now()->format('l j F Y, H:i T')) ?> via <?= e($transport === 'log' ? 'the log transport' : "SMTP ($host:$port)") ?>.
</p>
