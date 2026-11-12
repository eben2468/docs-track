<?php
/**
 * Staff / HOD dashboard (FR-11).
 *
 * @var array<string, mixed> $user
 * @var array<string, int> $cards incoming, with_us, outgoing, overdue, completed_month
 * @var int $stuckIncoming
 * @var list<array<string, mixed>> $awaiting
 * @var list<array<string, mixed>> $recent
 * @var bool $isHod
 * @var array{seconds: ?int, count: int}|null $processing HOD only
 * @var list<array<string, mixed>>|null $overdueList HOD only
 * @var int $alertHours
 */
$dept = (int) $user['department_id'];
$actionVerb = ['send' => 'sent', 'forward' => 'forwarded', 'return' => 'returned'];
?>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-4">
    <div>
        <h1 class="h4 mb-1">Good <?= (int) now()->format('G') < 12 ? 'morning' : ((int) now()->format('G') < 17 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', (string) $user['full_name'])[0]) ?></h1>
        <p class="text-body-secondary mb-0">Here is what is happening in your department today.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($user['role'] === 'admin'): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/')) ?>"><i class="bi bi-globe" aria-hidden="true"></i> University view</a>
        <?php endif; ?>
        <a class="btn btn-sm btn-primary" href="<?= e(url('/documents/create')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Register document</a>
    </div>
</div>

<div class="row g-3 mb-4 stat-grid">
    <div class="col-6 col-md-4 col-xl"><?= partial('partials/stat-card', ['label' => 'Incoming', 'value' => $cards['incoming'], 'icon' => 'inbox', 'href' => '/documents?tab=incoming', 'tone' => 'warning', 'hint' => $stuckIncoming > 0 ? "$stuckIncoming waiting over {$alertHours} h" : 'Awaiting our receipt']) ?></div>
    <div class="col-6 col-md-4 col-xl"><?= partial('partials/stat-card', ['label' => 'With us', 'value' => $cards['with_us'], 'icon' => 'folder2-open', 'href' => '/documents?tab=with-us', 'tone' => 'primary', 'hint' => 'Received, not completed']) ?></div>
    <div class="col-6 col-md-4 col-xl"><?= partial('partials/stat-card', ['label' => 'Outgoing', 'value' => $cards['outgoing'], 'icon' => 'send', 'href' => '/documents?tab=outgoing', 'tone' => 'secondary', 'hint' => 'Not yet received elsewhere']) ?></div>
    <div class="col-6 col-md-6 col-xl"><?= partial('partials/stat-card', ['label' => 'Overdue', 'value' => $cards['overdue'], 'icon' => 'alarm', 'href' => '/documents?tab=with-us&flag=overdue', 'tone' => 'danger', 'hint' => 'Held past due date']) ?></div>
    <div class="col-12 col-md-6 col-xl"><?= partial('partials/stat-card', ['label' => 'Completed this month', 'value' => $cards['completed_month'], 'icon' => 'check2-circle', 'href' => '/documents?tab=completed&flag=completed-month&current=' . $dept, 'tone' => 'success', 'hint' => now()->format('F Y')]) ?></div>
</div>

<?php if ($isHod): ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-body"><h2 class="h6 mb-0"><i class="bi bi-stopwatch" aria-hidden="true"></i> Average processing time</h2></div>
                <div class="card-body">
                    <?php if ($processing['seconds'] === null): ?>
                        <p class="text-body-secondary small mb-0">No documents finished processing in the last 30 days.</p>
                    <?php else: ?>
                        <div class="display-6 fw-semibold"><?= e(human_duration($processing['seconds'])) ?></div>
                        <p class="text-body-secondary small mb-0">
                            From receipt until sent on or completed, across <?= e($processing['count']) ?> document<?= $processing['count'] === 1 ? '' : 's' ?> in the last 30 days.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-body d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0"><i class="bi bi-alarm text-danger" aria-hidden="true"></i> Overdue in your department</h2>
                    <a class="small" href="<?= e(url('/documents', ['tab' => 'with-us', 'flag' => 'overdue'])) ?>">View all</a>
                </div>
                <?php if ($overdueList === []): ?>
                    <div class="card-body small text-body-secondary">Nothing is overdue. 👍</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light"><tr><th scope="col">Document</th><th scope="col">Held by</th><th scope="col">Due</th><th scope="col">Overdue by</th></tr></thead>
                            <tbody>
                            <?php foreach ($overdueList as $d): ?>
                                <tr>
                                    <td>
                                        <a class="font-monospace" href="<?= e(url("/documents/{$d['id']}")) ?>"><?= e($d['tracking_no']) ?></a>
                                        <div class="text-body-secondary text-truncate dash-title"><?= e($d['title']) ?></div>
                                    </td>
                                    <td><?= e($d['holder_name'] ?? '—') ?></td>
                                    <td class="text-nowrap"><?= e(format_datetime($d['lr_due_date'], 'j M Y')) ?></td>
                                    <td class="text-danger fw-semibold text-nowrap"><?= e(human_duration(seconds_since($d['lr_due_date'] . ' 23:59:59'))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><i class="bi bi-inbox" aria-hidden="true"></i> Awaiting your receipt</h2>
                <a class="small" href="<?= e(url('/documents', ['tab' => 'incoming'])) ?>">Open Incoming</a>
            </div>
            <?php if ($awaiting === []): ?>
                <div class="card-body small text-body-secondary">Nothing is waiting to be received.</div>
            <?php else: ?>
                <form method="post" action="<?= e(url('/documents/receive-bulk')) ?>">
                    <?= csrf_field() ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                            <tr>
                                <th scope="col" class="text-center"><input class="form-check-input" type="checkbox" data-check-all="document_ids[]" aria-label="Select all"></th>
                                <th scope="col">Document</th>
                                <th scope="col" class="d-none d-md-table-cell">From</th>
                                <th scope="col">Waiting</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($awaiting as $m):
                                $stuck = seconds_since($m['sent_at']) > $alertHours * 3600; ?>
                                <tr class="<?= $stuck ? 'row-stuck' : '' ?>">
                                    <td class="text-center"><input class="form-check-input" type="checkbox" name="document_ids[]" value="<?= e($m['document_id']) ?>" aria-label="Select <?= e($m['tracking_no']) ?>"></td>
                                    <td>
                                        <a class="font-monospace" href="<?= e(url("/documents/{$m['document_id']}")) ?>"><?= e($m['tracking_no']) ?></a>
                                        <?php if ($m['priority'] === 'urgent'): ?><span class="badge text-bg-danger">Urgent</span><?php endif; ?>
                                        <?php if ((int) $m['to_user_id'] === (int) $user['id']): ?><span class="badge text-bg-info">For you</span><?php endif; ?>
                                        <div class="text-body-secondary text-truncate dash-title"><?= e($m['title']) ?></div>
                                    </td>
                                    <td class="d-none d-md-table-cell"><?= e($m['from_department_name']) ?><div class="text-body-secondary"><?= e($m['from_user_name']) ?></div></td>
                                    <td class="text-nowrap<?= $stuck ? ' text-warning-emphasis fw-semibold' : '' ?>"><?= e(human_duration(seconds_since($m['sent_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-body text-end">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receive selected</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body"><h2 class="h6 mb-0"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Recently moved</h2></div>
            <?php if ($recent === []): ?>
                <div class="card-body small text-body-secondary">No movements yet.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($recent as $m):
                        $incoming = (int) $m['to_department_id'] === $dept;
                        $what = match ($m['status']) {
                            'received' => 'received by ' . $m['to_code'],
                            'recalled' => 'recalled by ' . $m['from_code'],
                            default => $actionVerb[$m['action']] . ' ' . $m['from_code'] . ' → ' . $m['to_code'],
                        }; ?>
                        <li class="list-group-item d-flex gap-2 align-items-start">
                            <i class="bi <?= $incoming ? 'bi-box-arrow-in-down-right text-success' : 'bi-box-arrow-up-right text-primary' ?> mt-1" aria-hidden="true"></i>
                            <div class="min-w-0 flex-grow-1">
                                <a class="font-monospace" href="<?= e(url("/documents/{$m['document_id']}")) ?>"><?= e($m['tracking_no']) ?></a>
                                <span class="text-body-secondary"><?= e($what) ?></span>
                                <div class="text-truncate"><?= e($m['title']) ?></div>
                            </div>
                            <span class="text-body-secondary text-nowrap"><?= time_ago((string) $m['activity_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
