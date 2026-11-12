<?php
/**
 * Main authenticated layout: sidebar + top bar.
 *
 * @var string $content
 * @var string|null $title
 * @var string|null $activeNav
 */
$appName = (string) config('app.name');
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $appName : $appName;
$activeNav ??= 'dashboard';
$user = auth();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="app-body">
<div class="app-shell">
    <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
        <div class="offcanvas-header app-sidebar-brand">
            <a class="app-brand" href="<?= e(url('/')) ?>" id="appSidebarLabel">
                <i class="bi bi-folder-symlink" aria-hidden="true"></i>
                <span>VVU DTS</span>
            </a>
            <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas"
                    data-bs-target="#appSidebar" aria-label="Close menu"></button>
        </div>
        <div class="offcanvas-body flex-column p-0">
            <nav class="nav flex-column app-nav" aria-label="Main navigation">
                <a class="nav-link<?= $activeNav === 'dashboard' ? ' active' : '' ?>" href="<?= e(url('/')) ?>"
                   <?= $activeNav === 'dashboard' ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-speedometer2" aria-hidden="true"></i> Dashboard
                </a>
            </nav>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <button class="btn btn-link app-topbar-toggle d-lg-none" type="button" data-bs-toggle="offcanvas"
                    data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open menu">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <span class="app-topbar-title text-truncate"><?= e($appName) ?></span>
            <?php if ($user !== null): ?>
                <span class="ms-auto small text-body-secondary">
                    <i class="bi bi-person-circle" aria-hidden="true"></i> <?= e($user['full_name']) ?>
                </span>
            <?php endif; ?>
        </header>

        <main class="app-content" id="main">
            <?= partial('partials/flash') ?>
            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
