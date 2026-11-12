<?php
/**
 * Pages that need no login (public tracking, error pages): branded hero band with the
 * page content in a card overlapping it.
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
<body class="public-body">
<header class="public-header">
    <div class="container d-flex align-items-center gap-2">
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
                <span class="app-brand-sub d-none d-sm-block"><?= e($appName) ?></span>
            </span>
        </a>
        <nav class="ms-auto d-flex gap-1 small" aria-label="Public">
            <a class="public-link" href="<?= e(url('/track')) ?>"><i class="bi bi-search" aria-hidden="true"></i><span class="d-none d-sm-inline"> Track</span></a>
            <a class="public-link" href="<?= e(url('/login')) ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> <span class="d-none d-sm-inline">Staff sign in</span><span class="d-sm-none">Sign in</span></a>
        </nav>
    </div>
</header>
<main class="public-main container">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>
<footer class="public-footer container">
    &copy; <?= e(now()->format('Y')) ?> Valley View University · <?= e($appName) ?>
</footer>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
