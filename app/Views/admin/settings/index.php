<?php
/**
 * Application settings (FR-15).
 *
 * @var array<string, string> $settings
 * @var bool $hasLogo
 */
$value = static fn (string $key): string => (string) old($key, $settings[$key]);
$numberField = static function (string $key, string $label, string $help, int $min, int $max) use ($value): string {
    return '<div class="col-md-6">'
        . '<label for="' . $key . '" class="form-label">' . e($label) . ' <span class="text-danger">*</span></label>'
        . '<input type="number" class="form-control' . invalid($key) . '" id="' . $key . '" name="' . $key . '" value="'
        . e($value($key)) . '" min="' . $min . '" max="' . $max . '" required aria-describedby="' . $key . 'Help">'
        . '<div id="' . $key . 'Help" class="form-text">' . e($help) . '</div>'
        . feedback($key)
        . '</div>';
};
?>
<h1 class="h4 mb-3">Settings</h1>

<form method="post" action="<?= e(url('/admin/settings')) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-body"><h2 class="h6 mb-0">General</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="app_name" class="form-label">Application name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control<?= invalid('app_name') ?>" id="app_name" name="app_name"
                                   value="<?= e($value('app_name')) ?>" required maxlength="100">
                            <?= feedback('app_name') ?>
                        </div>
                        <?= $numberField('default_sla_days', 'Default SLA (working days)', 'Used when a document type has no SLA of its own.', 1, 60) ?>
                        <?= $numberField('max_upload_mb', 'Maximum upload size (MB)', 'Per attachment. The server limit (upload_max_filesize) must be at least this.', 1, 50) ?>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-body"><h2 class="h6 mb-0">Alerts</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <?= $numberField('unreceived_alert_hours', 'Unreceived alert (hours)', 'Alert when a sent document has not been acknowledged after this many hours.', 1, 720) ?>
                        <?= $numberField('alert_repeat_hours', 'Alert repeat (hours)', 'The same alert is not sent again within this period.', 1, 720) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header bg-body"><h2 class="h6 mb-0">Branding</h2></div>
                <div class="card-body">
                    <div class="mb-4">
                        <label for="primary_color" class="form-label">Primary colour <span class="text-danger">*</span></label>
                        <div class="d-flex gap-2 align-items-center">
                            <input type="color" class="form-control form-control-color<?= invalid('primary_color') ?>" id="primary_color"
                                   name="primary_color" value="<?= e($value('primary_color')) ?>" required>
                            <code data-color-value><?= e($value('primary_color')) ?></code>
                        </div>
                        <?= feedback('primary_color') ?>
                    </div>

                    <div class="mb-2">
                        <span class="form-label d-block">Current logo</span>
                        <?php if ($hasLogo): ?>
                            <div class="settings-logo-preview mb-2">
                                <img src="<?= e(url('/branding/logo')) ?>" alt="Current logo">
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" value="1" id="remove_logo" name="remove_logo">
                                <label class="form-check-label" for="remove_logo">Remove the logo</label>
                            </div>
                        <?php else: ?>
                            <p class="small text-body-secondary">No logo uploaded; a default icon is shown.</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="logo" class="form-label"><?= $hasLogo ? 'Replace logo' : 'Upload logo' ?></label>
                        <input type="file" class="form-control<?= invalid('logo') ?>" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
                        <div class="form-text">PNG, JPG or WebP, up to 1 MB. A square image works best.</div>
                        <?= feedback('logo') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save settings</button>
    </div>
</form>

<div class="card shadow-sm mt-4 form-narrow">
    <div class="card-header bg-body"><h2 class="h6 mb-0">Email delivery</h2></div>
    <div class="card-body">
        <p class="small text-body-secondary">
            SMTP settings come from the server's <code>.env</code> file (MAIL_*). Current transport:
            <strong><?= e(config('mail.transport') === 'log' ? 'log file (storage/logs/mail.log)' : 'SMTP ' . config('mail.host') . ':' . config('mail.port')) ?></strong>.
            Send a test to check they work; it is sent immediately, not queued.
        </p>
        <form method="post" action="<?= e(url('/admin/settings/test-email')) ?>" novalidate>
            <?= csrf_field() ?>
            <label for="test_email" class="form-label">Send a test email to</label>
            <div class="input-group has-validation">
                <input type="email" class="form-control<?= invalid('test_email') ?>" id="test_email" name="test_email" maxlength="150" required
                       value="<?= e(old('test_email', auth()['email'] ?? '')) ?>">
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-envelope-check" aria-hidden="true"></i> Send test email</button>
                <?= feedback('test_email') ?>
            </div>
        </form>
    </div>
</div>
