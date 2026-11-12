<?php
/**
 * Layout for sign-in / password reset screens: a single centred card.
 *
 * @var string $content
 * @var string|null $title
 */
$appName = setting('app_name');
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $appName : $appName;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/branding/theme', ['v' => substr(md5(setting('primary_color')), 0, 8)])) ?>">
</head>
<body class="auth-body">
<main class="auth-wrapper">
    <div class="auth-card card shadow-sm">
        <div class="card-body p-4 p-sm-5">
            <div class="text-center mb-4">
                <?php if (setting('logo_path') !== ''): ?>
                    <img src="<?= e(url('/branding/logo')) ?>" alt="" class="auth-logo-img mb-2">
                <?php else: ?>
                    <div class="auth-logo"><i class="bi bi-folder-symlink" aria-hidden="true"></i></div>
                <?php endif; ?>
                <h1 class="h5 mb-0"><?= e($appName) ?></h1>
                <p class="text-body-secondary small mb-0">Valley View University</p>
            </div>
            <?= partial('partials/flash') ?>
            <?= $content ?>
        </div>
    </div>
</main>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
