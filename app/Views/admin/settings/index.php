<?php
/**
 * Application settings (FR-15).
 *
 * @var array<string, string> $settings
 * @var bool $hasLogo
 * @var array<string, string|int> $mail Mail settings in effect (password included; never printed)
 * @var list<string> $mailOverrides Mail keys saved here rather than taken from .env
 * @var string $mailPasswordStatus saved|unreadable|env|none
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

<?php
$mailValue = static fn (string $key): string => (string) old('mail_' . $key, (string) $mail[$key]);
$mailEncryption = $mailValue('encryption');
$mailEncryption = in_array($mailEncryption, ['tls', 'ssl'], true) ? $mailEncryption : ($mailEncryption === '' ? 'tls' : 'none');
$passwordNote = match ($mailPasswordStatus) {
    'saved' => 'A password is saved (encrypted). Leave blank to keep it.',
    'env' => 'Using the password from .env. Enter one to save it here instead.',
    'unreadable' => 'The saved password can no longer be read (APP_KEY changed). Enter it again.',
    default => 'No password set.',
};
?>
<div class="row g-4 mt-0" id="email">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="h6 mb-0"><i class="bi bi-envelope-at" aria-hidden="true"></i> Email (SMTP)</h2>
                <span class="badge <?= $mailOverrides === [] ? 'text-bg-secondary' : 'text-bg-primary' ?>">
                    <?= $mailOverrides === [] ? 'Using .env values' : 'Using values saved here' ?>
                </span>
            </div>
            <div class="card-body">
                <p class="small text-body-secondary">
                    Sign-in codes, welcome emails and notifications are sent with these settings. Values saved here
                    override the <code>MAIL_*</code> values in the server's <code>.env</code> file.
                    <strong>Nobody can sign in if email stops working</strong>, so send a test after any change.
                </p>
                <form method="post" action="<?= e(url('/admin/settings/mail')) ?>" novalidate autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="mail_transport" class="form-label">Delivery <span class="text-danger">*</span></label>
                            <select class="form-select<?= invalid('mail_transport') ?>" id="mail_transport" name="mail_transport">
                                <option value="smtp"<?= $mailValue('transport') === 'smtp' ? ' selected' : '' ?>>Send with SMTP</option>
                                <option value="log"<?= $mailValue('transport') === 'log' ? ' selected' : '' ?>>Log file only (testing)</option>
                            </select>
                            <?= feedback('mail_transport') ?>
                        </div>
                        <div class="col-md-6">
                            <span class="form-label d-block">Quick setup</span>
                            <button type="button" class="btn btn-outline-secondary w-100" data-mail-preset="smtp.gmail.com|587|tls">
                                <i class="bi bi-google" aria-hidden="true"></i> Fill in Gmail settings
                            </button>
                        </div>
                        <div class="col-md-6">
                            <label for="mail_host" class="form-label">SMTP host <span class="text-danger">*</span></label>
                            <input type="text" class="form-control<?= invalid('mail_host') ?>" id="mail_host" name="mail_host"
                                   value="<?= e($mailValue('host')) ?>" maxlength="255" placeholder="smtp.gmail.com" spellcheck="false">
                            <?= feedback('mail_host') ?>
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="mail_port" class="form-label">Port <span class="text-danger">*</span></label>
                            <input type="number" class="form-control<?= invalid('mail_port') ?>" id="mail_port" name="mail_port"
                                   value="<?= e($mailValue('port')) ?>" min="1" max="65535">
                            <?= feedback('mail_port') ?>
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="mail_encryption" class="form-label">Encryption <span class="text-danger">*</span></label>
                            <select class="form-select<?= invalid('mail_encryption') ?>" id="mail_encryption" name="mail_encryption">
                                <option value="tls"<?= $mailEncryption === 'tls' ? ' selected' : '' ?>>STARTTLS</option>
                                <option value="ssl"<?= $mailEncryption === 'ssl' ? ' selected' : '' ?>>SSL/TLS</option>
                                <option value="none"<?= $mailEncryption === 'none' ? ' selected' : '' ?>>None</option>
                            </select>
                            <?= feedback('mail_encryption') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="mail_username" class="form-label">Username</label>
                            <input type="text" class="form-control<?= invalid('mail_username') ?>" id="mail_username" name="mail_username"
                                   value="<?= e($mailValue('username')) ?>" maxlength="255" autocomplete="off" spellcheck="false"
                                   placeholder="you@gmail.com">
                            <?= feedback('mail_username') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="mail_password" class="form-label">Password</label>
                            <div class="position-relative">
                                <input type="password" class="form-control pe-5<?= invalid('mail_password') ?>" id="mail_password" name="mail_password"
                                       maxlength="255" autocomplete="new-password" aria-describedby="mailPasswordHelp"
                                       placeholder="<?= $mailPasswordStatus === 'saved' ? '•••••••• (saved)' : '' ?>">
                                <button type="button" class="btn btn-link input-action" data-toggle-password="mail_password" aria-label="Show password">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                                <?= feedback('mail_password') ?>
                            </div>
                            <div id="mailPasswordHelp" class="form-text<?= $mailPasswordStatus === 'unreadable' ? ' text-danger' : '' ?>"><?= e($passwordNote) ?></div>
                            <?php if ($mailPasswordStatus === 'saved' || $mailPasswordStatus === 'unreadable'): ?>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" value="1" id="mail_clear_password" name="mail_clear_password">
                                    <label class="form-check-label small" for="mail_clear_password">Remove the saved password</label>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="mail_from_address" class="form-label">From address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control<?= invalid('mail_from_address') ?>" id="mail_from_address" name="mail_from_address"
                                   value="<?= e($mailValue('from_address')) ?>" maxlength="150" aria-describedby="mailFromHelp">
                            <div id="mailFromHelp" class="form-text">For Gmail, use the same address as the username.</div>
                            <?= feedback('mail_from_address') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="mail_from_name" class="form-label">From name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control<?= invalid('mail_from_name') ?>" id="mail_from_name" name="mail_from_name"
                                   value="<?= e($mailValue('from_name')) ?>" maxlength="100">
                            <?= feedback('mail_from_name') ?>
                        </div>
                    </div>
                    <div class="alert alert-light border small mt-3 mb-0">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <strong>Gmail:</strong> turn on 2-Step Verification, then create an <em>App Password</em> at
                        myaccount.google.com/apppasswords and paste the 16 characters as the password. Your normal Gmail
                        password will not work.
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save email settings</button>
                    </div>
                </form>
                <?php if ($mailOverrides !== []): ?>
                    <form method="post" action="<?= e(url('/admin/settings/mail/reset')) ?>" class="mt-2"
                          data-confirm="Forget the email settings saved here and use the values in .env?" data-confirm-button="Reset">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-link btn-sm px-0 text-danger"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset to .env values</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-body"><h2 class="h6 mb-0"><i class="bi bi-send-check" aria-hidden="true"></i> Test email</h2></div>
            <div class="card-body">
                <p class="small text-body-secondary">
                    Currently:
                    <strong><?= e($mail['transport'] === 'log' ? 'log file (storage/logs/mail.log)' : 'SMTP ' . $mail['host'] . ':' . $mail['port']) ?></strong>.
                    The test uses the saved settings and is sent immediately, not queued.
                </p>
                <form method="post" action="<?= e(url('/admin/settings/test-email')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <label for="test_email" class="form-label">Send a test email to</label>
                    <div class="input-group has-validation">
                        <input type="email" class="form-control<?= invalid('test_email') ?>" id="test_email" name="test_email" maxlength="150" required
                               value="<?= e(old('test_email', auth()['email'] ?? '')) ?>">
                        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-envelope-check" aria-hidden="true"></i> Send</button>
                        <?= feedback('test_email') ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
