<?php
/**
 * Sign-in / password screens: brand panel (left on desktop, top on phones) + form.
 *
 * @var string $content
 * @var string|null $title
 */
$appName = setting('app_name');
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $appName : $appName;
$hasLogo = setting('logo_path') !== '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e(setting('primary_color')) ?>">
    <title><?= e($pageTitle) ?></title>
    <?= favicon_tags() ?>
    <link rel="preload" href="<?= e(asset('fonts/inter-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/branding/theme', ['v' => substr(md5(setting('primary_color')), 0, 8)])) ?>">
</head>
<body class="auth-body">
<div class="auth-split">
    <section class="auth-brand" aria-label="About the system">
        <div class="auth-brand-inner">
            <a class="app-brand" href="<?= e(url('/track')) ?>">
                <span class="app-brand-mark" aria-hidden="true">
                    <?php if ($hasLogo): ?>
                        <img src="<?= e(url('/branding/logo')) ?>" alt="">
                    <?php else: ?>
                        <i class="bi bi-folder-symlink-fill"></i>
                    <?php endif; ?>
                </span>
                <span class="app-brand-text">
                    <span class="app-brand-name">Valley View University</span>
                    <span class="app-brand-sub"><?= e($appName) ?></span>
                </span>
            </a>

            <div class="auth-hide-mobile">
                <h2>Know exactly where every document is.</h2>
                <p class="lead">Register, send and receive documents between departments with a permanent, auditable trail.</p>
            </div>

            <ul class="auth-features auth-hide-mobile">
                <li><i class="bi bi-qr-code" aria-hidden="true"></i><span><strong>Tracking numbers &amp; QR labels</strong>Every document gets a unique number and a printable label.</span></li>
                <li><i class="bi bi-arrow-left-right" aria-hidden="true"></i><span><strong>Acknowledged hand-overs</strong>A document is only “with” a department once it is received.</span></li>
                <li><i class="bi bi-bell" aria-hidden="true"></i><span><strong>Alerts that matter</strong>Unreceived and overdue documents are flagged automatically.</span></li>
            </ul>

            <div class="auth-brand-foot auth-hide-mobile">&copy; <?= e(now()->format('Y')) ?> Valley View University</div>
        </div>
    </section>

    <main class="auth-main">
        <div class="auth-panel">
            <?= partial('partials/flash') ?>
            <?= $content ?>
            <p class="text-center small text-body-secondary mt-4 mb-0">
                <i class="bi bi-search" aria-hidden="true"></i> Have a tracking number?
                <a href="<?= e(url('/track')) ?>">Track a document</a>
            </p>
        </div>
    </main>
</div>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
