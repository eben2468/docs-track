<?php
/**
 * Main authenticated layout: sidebar (spec §11) + top bar.
 *
 * @var string $content
 * @var string|null $title
 */
$appName = setting('app_name');
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $appName : $appName;
$user = auth();
$path = current_path();
$isAdmin = $user !== null && $user['role'] === 'admin';
$hasLogo = setting('logo_path') !== '';

/*
 * Menu items: [label, icon, path or null]. Items with a null path belong to later
 * build phases and are shown disabled until their pages exist.
 */
$mainMenu = [
    ['Dashboard', 'speedometer2', '/'],
    ['Register Document', 'file-earmark-plus', '/documents/create'],
    ['Incoming', 'inbox', null],
    ['With Us', 'folder2-open', null],
    ['Outgoing', 'send', null],
    ['Registered', 'journal-text', null],
    ['Completed', 'check2-square', null],
    ['Scan QR', 'qr-code-scan', null],
];
$adminMenu = [
    ['Users', 'people', '/admin/users'],
    ['Departments', 'diagram-3', '/admin/departments'],
    ['Document Types', 'tags', '/admin/document-types'],
    ['Holidays', 'calendar-event', '/admin/holidays'],
    ['Settings', 'gear', '/admin/settings'],
    ['Audit Logs', 'shield-check', null],
];
$isActive = static fn (string $itemPath): bool => $itemPath === '/' ? $path === '/' : ($path === $itemPath || str_starts_with($path, $itemPath . '/'));
$renderItem = static function (array $item) use ($isActive): string {
    [$label, $icon, $itemPath] = $item;
    if ($itemPath === null) {
        return '<span class="nav-link disabled" aria-disabled="true" title="Available in a later release">'
            . '<i class="bi bi-' . e($icon) . '" aria-hidden="true"></i> ' . e($label)
            . ' <span class="badge app-soon ms-auto">Soon</span></span>';
    }
    $active = $isActive($itemPath);

    return '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e(url($itemPath)) . '"'
        . ($active ? ' aria-current="page"' : '') . '>'
        . '<i class="bi bi-' . e($icon) . '" aria-hidden="true"></i> ' . e($label) . '</a>';
};
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
    <link rel="stylesheet" href="<?= e(url('/branding/theme', ['v' => substr(md5(setting('primary_color')), 0, 8)])) ?>">
</head>
<body class="app-body">
<a class="visually-hidden-focusable app-skip-link" href="#main">Skip to content</a>
<div class="app-shell">
    <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
        <div class="offcanvas-header app-sidebar-brand">
            <a class="app-brand" href="<?= e(url('/')) ?>" id="appSidebarLabel">
                <?php if ($hasLogo): ?>
                    <img src="<?= e(url('/branding/logo')) ?>" alt="" class="app-brand-logo">
                <?php else: ?>
                    <i class="bi bi-folder-symlink" aria-hidden="true"></i>
                <?php endif; ?>
                <span>VVU DTS</span>
            </a>
            <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas"
                    data-bs-target="#appSidebar" aria-label="Close menu"></button>
        </div>
        <div class="offcanvas-body flex-column p-0">
            <nav class="nav flex-column app-nav" aria-label="Main navigation">
                <?php foreach ($mainMenu as $item): ?>
                    <?= $renderItem($item) ?>
                <?php endforeach; ?>

                <?php if ($isAdmin): ?>
                    <div class="app-nav-heading">Administration</div>
                    <?php foreach ($adminMenu as $item): ?>
                        <?= $renderItem($item) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <button class="btn btn-link app-topbar-toggle d-lg-none" type="button" data-bs-toggle="offcanvas"
                    data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open menu">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>

            <form class="app-search d-none d-md-block" role="search" action="#" title="Quick tracking search is available in a later release">
                <label class="visually-hidden" for="quickTrack">Track a document by number</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="search" class="form-control" id="quickTrack" placeholder="Tracking no. e.g. VVU-REG-2026-00042" disabled>
                </div>
            </form>

            <div class="ms-auto d-flex align-items-center gap-1">
                <button type="button" class="btn btn-link app-topbar-icon" disabled
                        title="Notifications are available in a later release" aria-label="Notifications">
                    <i class="bi bi-bell" aria-hidden="true"></i>
                </button>

                <?php if ($user !== null): ?>
                    <div class="dropdown">
                        <button class="btn btn-link app-user-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown"
                                aria-expanded="false">
                            <span class="app-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
                            <span class="d-none d-sm-inline"><?= e($user['full_name']) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li class="px-3 py-2">
                                <div class="fw-semibold"><?= e($user['full_name']) ?></div>
                                <div class="small text-body-secondary"><?= e(role_label($user['role'])) ?></div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="<?= e(url('/profile')) ?>">
                                    <i class="bi bi-person" aria-hidden="true"></i> My profile
                                </a>
                            </li>
                            <li>
                                <form method="post" action="<?= e(url('/logout')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="dropdown-item">
                                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <main class="app-content" id="main">
            <?= partial('partials/flash') ?>
            <?= $content ?>
        </main>
    </div>
</div>

<?= partial('partials/confirm-modal') ?>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
