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
$initial = $user !== null ? mb_strtoupper(mb_substr((string) $user['full_name'], 0, 1)) : '?';
$departmentName = $user !== null ? current_department_name() : '';

$currentTab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
$incomingCount = $user !== null ? incoming_count() : 0;

/*
 * Menu items: [label, icon, path, badge]. "/documents?tab=x" items are active when that
 * list tab is open.
 */
$mainMenu = [
    ['Dashboard', 'grid-1x2', '/'],
    ['Register Document', 'file-earmark-plus', '/documents/create'],
    ['Incoming', 'inbox', '/documents?tab=incoming', $incomingCount],
    ['With Us', 'folder2-open', '/documents?tab=with-us'],
    ['Outgoing', 'send', '/documents?tab=outgoing'],
    ['Registered', 'journal-text', '/documents?tab=registered'],
    ['Completed', 'check2-square', '/documents?tab=completed'],
    ['Scan QR', 'qr-code-scan', '/scan'],
];
$adminMenu = [
    ['Users', 'people', '/admin/users'],
    ['Departments', 'diagram-3', '/admin/departments'],
    ['Document Types', 'tags', '/admin/document-types'],
    ['Holidays', 'calendar-event', '/admin/holidays'],
    ['Settings', 'sliders', '/admin/settings'],
    ['Audit Logs', 'shield-check', '/admin/audit-logs'],
];
$isActive = static function (string $itemPath) use ($path, $currentTab): bool {
    if (str_contains($itemPath, '?tab=')) {
        [$itemBase, $itemTab] = explode('?tab=', $itemPath, 2);

        return $path === $itemBase && ($currentTab === $itemTab || ($currentTab === '' && $itemTab === 'incoming'));
    }

    return $itemPath === '/' ? $path === '/' : ($path === $itemPath || str_starts_with($path, $itemPath . '/'));
};
$renderItem = static function (array $item) use ($isActive): string {
    [$label, $icon, $itemPath] = $item;
    $badge = (int) ($item[3] ?? 0);
    $active = $isActive($itemPath);

    return '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e(url($itemPath)) . '"'
        . ($active ? ' aria-current="page"' : '') . '>'
        . '<i class="bi bi-' . e($icon) . '" aria-hidden="true"></i><span>' . e($label) . '</span>'
        . ($badge > 0 ? '<span class="badge rounded-pill">' . $badge . '<span class="visually-hidden"> waiting</span></span>' : '')
        . '</a>';
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e(setting('primary_color')) ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(asset('fonts/inter-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/branding/theme', ['v' => substr(md5(setting('primary_color')), 0, 8)])) ?>">
</head>
<body class="app-body">
<a class="visually-hidden-focusable app-skip-link" href="#main">Skip to content</a>
<div class="app-shell">
    <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-label="Main navigation">
        <div class="app-sidebar-inner">
            <div class="app-sidebar-brand">
                <a class="app-brand" href="<?= e(url('/')) ?>">
                    <span class="app-brand-mark" aria-hidden="true">
                        <?php if ($hasLogo): ?>
                            <img src="<?= e(url('/branding/logo')) ?>" alt="">
                        <?php else: ?>
                            <i class="bi bi-folder-symlink-fill"></i>
                        <?php endif; ?>
                    </span>
                    <span class="app-brand-text">
                        <span class="app-brand-name">VVU DTS</span>
                        <span class="app-brand-sub">Document Tracking System</span>
                    </span>
                </a>
                <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas"
                        data-bs-target="#appSidebar" aria-label="Close menu"></button>
            </div>

            <div class="offcanvas-body flex-column">
                <nav class="nav flex-column app-nav" aria-label="Documents">
                    <?php foreach ($mainMenu as $item): ?>
                        <?= $renderItem($item) ?>
                    <?php endforeach; ?>
                </nav>

                <?php if ($isAdmin): ?>
                    <div class="app-nav-heading" id="adminNavHeading">Administration</div>
                    <nav class="nav flex-column app-nav" aria-labelledby="adminNavHeading">
                        <?php foreach ($adminMenu as $item): ?>
                            <?= $renderItem($item) ?>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
            </div>

            <?php if ($user !== null): ?>
                <div class="app-sidebar-user">
                    <span class="app-avatar" aria-hidden="true"><?= e($initial) ?></span>
                    <div class="min-w-0">
                        <div class="app-sidebar-user-name"><?= e($user['full_name']) ?></div>
                        <div class="app-sidebar-user-meta"><?= e(role_label($user['role'])) ?><?= $departmentName !== '' ? ' · ' . e($departmentName) : '' ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <button class="app-topbar-toggle d-lg-none" type="button" data-bs-toggle="offcanvas"
                    data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open menu">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <a class="app-brand app-topbar-brand d-lg-none" href="<?= e(url('/')) ?>" aria-label="Dashboard">
                <span class="app-brand-mark" aria-hidden="true">
                    <?php if ($hasLogo): ?><img src="<?= e(url('/branding/logo')) ?>" alt=""><?php else: ?><i class="bi bi-folder-symlink-fill"></i><?php endif; ?>
                </span>
                <span class="app-brand-name d-none d-sm-inline">VVU DTS</span>
            </a>

            <form class="app-search" id="appSearch" role="search" method="get" action="<?= e(url('/search')) ?>">
                <label class="visually-hidden" for="quickTrack">Track a document by number or keyword</label>
                <i class="bi bi-search app-search-icon" aria-hidden="true"></i>
                <input type="search" class="form-control" id="quickTrack" name="q" maxlength="100"
                       placeholder="Search tracking no. or keyword" autocomplete="off">
                <kbd class="app-search-kbd" aria-hidden="true">/</kbd>
            </form>

            <div class="ms-auto d-flex align-items-center gap-1 gap-sm-2">
                <button type="button" class="app-topbar-icon d-md-none" data-search-toggle aria-controls="appSearch"
                        aria-expanded="false" aria-label="Search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </button>
                <a class="app-topbar-icon d-none d-sm-inline-flex" href="<?= e(url('/scan')) ?>" title="Scan QR label" aria-label="Scan QR label">
                    <i class="bi bi-qr-code-scan" aria-hidden="true"></i>
                </a>

                <?php if ($user !== null): $unread = unread_notification_count(); ?>
                    <div class="dropdown" id="notifBell" data-poll-url="<?= e(url('/api/notifications/unread-count')) ?>">
                        <button type="button" class="app-topbar-icon position-relative" data-bs-toggle="dropdown"
                                data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                            <i class="bi bi-bell" aria-hidden="true"></i>
                            <span class="badge rounded-pill text-bg-danger notif-badge<?= $unread > 0 ? '' : ' d-none' ?>" data-notif-count><?= $unread > 99 ? '99+' : e($unread) ?></span>
                            <span class="visually-hidden" data-notif-label><?= e($unread) ?> unread</span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end notif-menu">
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                                <strong>Notifications</strong>
                                <form method="post" action="<?= e(url('/notifications/read-all')) ?>" data-notif-read-all>
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-link btn-sm p-0">Mark all read</button>
                                </form>
                            </div>
                            <div class="notif-list" data-notif-list>
                                <div class="px-3 py-3 small text-body-secondary">Loading…</div>
                            </div>
                            <a class="d-block text-center small fw-semibold py-2 border-top" href="<?= e(url('/notifications')) ?>">View all notifications</a>
                        </div>
                    </div>

                    <div class="dropdown">
                        <button class="app-user-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="app-avatar" aria-hidden="true"><?= e($initial) ?></span>
                            <span class="d-none d-md-block">
                                <span class="app-user-name d-block"><?= e($user['full_name']) ?></span>
                                <span class="app-user-role d-block"><?= e(role_label($user['role'])) ?></span>
                            </span>
                            <span class="visually-hidden d-md-none">Account menu</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li class="px-2 pt-1 pb-2">
                                <div class="fw-semibold"><?= e($user['full_name']) ?></div>
                                <div class="small text-body-secondary"><?= e($user['email']) ?></div>
                                <?php if ($departmentName !== ''): ?><div class="small text-body-secondary"><?= e($departmentName) ?></div><?php endif; ?>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= e(url('/profile')) ?>"><i class="bi bi-person-circle" aria-hidden="true"></i> My profile</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('/notifications')) ?>"><i class="bi bi-bell" aria-hidden="true"></i> Notifications</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= e(url('/logout')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="dropdown-item text-danger">
                                        <i class="bi bi-box-arrow-right text-danger" aria-hidden="true"></i> Sign out
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

        <footer class="app-footer">&copy; <?= e(now()->format('Y')) ?> Valley View University · <?= e($appName) ?></footer>
    </div>
</div>

<?= partial('partials/confirm-modal') ?>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
