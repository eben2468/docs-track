<?php
/**
 * Layout for pages that need no login (public tracking, error pages).
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
<body class="public-body">
<header class="public-header">
    <div class="container d-flex align-items-center">
        <a class="app-brand" href="<?= e(url('/')) ?>">
            <i class="bi bi-folder-symlink" aria-hidden="true"></i>
            <span><?= e($appName) ?></span>
        </a>
    </div>
</header>
<main class="container py-5">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>
<footer class="container pb-4 text-center small text-body-secondary">
    &copy; <?= e(now()->format('Y')) ?> Valley View University
</footer>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
