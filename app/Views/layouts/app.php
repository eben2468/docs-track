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

$currentTab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
$incomingCount = $user !== null ? incoming_count() : 0;

/*
 * Menu items: [label, icon, path or null, badge]. Items with a null path belong to later
 * build phases and are shown disabled until their pages exist. "/documents?tab=x" items
 * are active when that list tab is open.
 */
$mainMenu = [
    ['Dashboard', 'speedometer2', '/'],
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
    ['Settings', 'gear', '/admin/settings'],
    ['Audit Logs', 'shield-check', null],
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
    if ($itemPath === null) {
        return '<span class="nav-link disabled" aria-disabled="true" title="Available in a later release">'
            . '<i class="bi bi-' . e($icon) . '" aria-hidden="true"></i> ' . e($label)
            . ' <span class="badge app-soon ms-auto">Soon</span></span>';
    }
    $active = $isActive($itemPath);

    return '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e(url($itemPath)) . '"'
        . ($active ? ' aria-current="page"' : '') . '>'
        . '<i class="bi bi-' . e($icon) . '" aria-hidden="true"></i> ' . e($label)
        . ($badge > 0 ? ' <span class="badge rounded-pill text-bg-warning ms-auto">' . $badge . '<span class="visually-hidden"> waiting</span></span>' : '')
        . '</a>';
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

            <form class="app-search" role="search" method="get" action="<?= e(url('/search')) ?>">
                <label class="visually-hidden" for="quickTrack">Track a document by number or keyword</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="search" class="form-control" id="quickTrack" name="q" maxlength="100"
                           placeholder="Tracking no. or keyword" autocomplete="off">
                </div>
            </form>

            <div class="ms-auto d-flex align-items-center gap-1">
                <?php if ($user !== null): $unread = unread_notification_count(); ?>
                    <div class="dropdown" id="notifBell" data-poll-url="<?= e(url('/api/notifications/unread-count')) ?>">
                        <button type="button" class="btn btn-link app-topbar-icon position-relative" data-bs-toggle="dropdown"
                                data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                            <i class="bi bi-bell" aria-hidden="true"></i>
                            <span class="badge rounded-pill text-bg-danger notif-badge<?= $unread > 0 ? '' : ' d-none' ?>" data-notif-count><?= $unread > 99 ? '99+' : e($unread) ?></span>
                            <span class="visually-hidden" data-notif-label><?= e($unread) ?> unread</span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end shadow notif-menu p-0">
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                                <strong class="small">Notifications</strong>
                                <form method="post" action="<?= e(url('/notifications/read-all')) ?>" data-notif-read-all>
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-link btn-sm p-0">Mark all read</button>
                                </form>
                            </div>
                            <div class="notif-list" data-notif-list>
                                <div class="px-3 py-3 small text-body-secondary">Loading…</div>
                            </div>
                            <a class="d-block text-center small py-2 border-top" href="<?= e(url('/notifications')) ?>">View all notifications</a>
                        </div>
                    </div>
                <?php endif; ?>

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
