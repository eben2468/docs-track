<?php
/**
 * The user's notifications (FR-9).
 *
 * @var array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int} $result
 * @var bool $unreadOnly
 * @var int $unreadCount
 */
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h1 class="h4 mb-0">Notifications</h1>
    <?php if ($unreadCount > 0): ?>
        <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-check2-all" aria-hidden="true"></i> Mark all as read</button>
        </form>
    <?php endif; ?>
</div>

<ul class="nav nav-pills doc-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link<?= $unreadOnly ? '' : ' active' ?>" href="<?= e(url('/notifications')) ?>"<?= $unreadOnly ? '' : ' aria-current="page"' ?>>All</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $unreadOnly ? ' active' : '' ?>" href="<?= e(url('/notifications', ['filter' => 'unread'])) ?>"<?= $unreadOnly ? ' aria-current="page"' : '' ?>>
            Unread <?php if ($unreadCount > 0): ?><span class="badge rounded-pill <?= $unreadOnly ? 'text-bg-light' : 'text-bg-danger' ?>"><?= e($unreadCount) ?></span><?php endif; ?>
        </a>
    </li>
</ul>

<div class="card shadow-sm">
    <?php if ($result['data'] === []): ?>
        <div class="card-body text-center text-body-secondary py-5">
            <i class="bi bi-bell-slash fs-3 d-block mb-2" aria-hidden="true"></i>
            <?= $unreadOnly ? 'You have no unread notifications.' : 'You have no notifications yet.' ?>
        </div>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($result['data'] as $n):
                $unread = (int) $n['is_read'] === 0; ?>
                <li class="list-group-item d-flex gap-3 align-items-start notif-row<?= $unread ? ' is-unread' : '' ?>">
                    <i class="bi bi-<?= e(notification_icon((string) $n['type'])) ?> fs-5 notif-icon notif-<?= e($n['type']) ?>" aria-hidden="true"></i>
                    <div class="flex-grow-1 min-w-0">
                        <a class="notif-link text-break<?= $unread ? ' fw-semibold' : '' ?>" href="<?= e(url("/notifications/{$n['id']}/open")) ?>">
                            <?= e($n['message']) ?>
                        </a>
                        <div class="small text-body-secondary">
                            <?= time_ago((string) $n['created_at']) ?>
                            <?php if ($n['tracking_no']): ?> · <span class="font-monospace"><?= e($n['tracking_no']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($unread): ?>
                        <form method="post" action="<?= e(url("/notifications/{$n['id']}/read")) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-link text-body-secondary p-0" title="Mark as read">
                                <i class="bi bi-check2" aria-hidden="true"></i><span class="visually-hidden">Mark as read</span>
                            </button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?= partial('partials/pagination', [
    'page' => $result['page'], 'perPage' => $result['per_page'], 'total' => $result['total'],
    'path' => '/notifications', 'query' => $unreadOnly ? ['filter' => 'unread'] : [],
]) ?>
