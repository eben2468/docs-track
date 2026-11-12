<?php
/**
 * University-wide admin dashboard (FR-11).
 *
 * @var array<string, int> $byStatus
 * @var int $total
 * @var int $overdueTotal
 * @var int $stuckTotal
 * @var int $completedMonth
 * @var list<array{id: int, code: string, name: string, overdue: int}> $topOverdue
 * @var list<array<string, mixed>> $stuck
 * @var list<array<string, mixed>> $activity
 * @var int $alertHours
 */
use App\Services\DashboardService;

$statusMeta = [
    'draft' => ['Draft', '#98a2b3'],
    'in_transit' => ['In transit', '#f79009'],
    'received' => ['Received', '#2e6ff2'],
    'completed' => ['Completed', '#12b76a'],
    'cancelled' => ['Cancelled', '#344054'],
];
$statusChart = [
    'type' => 'doughnut',
    'labels' => array_map(static fn (string $s): string => $statusMeta[$s][0], array_keys($byStatus)),
    'values' => array_values($byStatus),
    'colors' => array_map(static fn (string $s): string => $statusMeta[$s][1], array_keys($byStatus)),
];
$overdueChart = [
    'type' => 'bar',
    'labels' => array_column($topOverdue, 'code'),
    'values' => array_column($topOverdue, 'overdue'),
    'colors' => array_fill(0, count($topOverdue), '#f04438'),
    'label' => 'Overdue documents',
];
?>
<section class="hero-card">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 position-relative">
        <div>
            <div class="hero-eyebrow"><?= e(now()->format('l, j F Y')) ?> · Administrator</div>
            <h1>University overview</h1>
            <p><?= e(number_format($total)) ?> documents registered in total<?= $overdueTotal > 0 ? ' · <strong class="text-white">' . e(number_format($overdueTotal)) . ' overdue</strong>' : '' ?><?= $stuckTotal > 0 ? ' · <strong class="text-white">' . e(number_format($stuckTotal)) . ' not received in ' . e($alertHours) . ' h</strong>' : '' ?>.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-hero-ghost" href="<?= e(url('/', ['view' => 'department'])) ?>"><i class="bi bi-building" aria-hidden="true"></i> My department</a>
            <a class="btn btn-light" href="<?= e(url('/documents', ['tab' => 'all'])) ?>"><i class="bi bi-collection" aria-hidden="true"></i> All documents</a>
        </div>
    </div>
</section>

<div class="row g-3 mb-4 stat-grid">
    <div class="col-6 col-md-3"><?= partial('partials/stat-card', ['label' => 'In transit', 'value' => $byStatus['in_transit'], 'icon' => 'truck', 'href' => '/documents?tab=all&status=in_transit', 'tone' => 'warning', 'hint' => "$stuckTotal not received in {$alertHours} h"]) ?></div>
    <div class="col-6 col-md-3"><?= partial('partials/stat-card', ['label' => 'Being processed', 'value' => $byStatus['received'], 'icon' => 'folder2-open', 'href' => '/documents?tab=all&status=received', 'tone' => 'primary', 'hint' => 'Received, not completed']) ?></div>
    <div class="col-6 col-md-3"><?= partial('partials/stat-card', ['label' => 'Overdue', 'value' => $overdueTotal, 'icon' => 'alarm', 'href' => '/documents?tab=all&flag=overdue', 'tone' => 'danger', 'hint' => 'Held past due date']) ?></div>
    <div class="col-6 col-md-3"><?= partial('partials/stat-card', ['label' => 'Completed this month', 'value' => $completedMonth, 'icon' => 'check2-circle', 'href' => '/documents?tab=all&flag=completed-month', 'tone' => 'success', 'hint' => now()->format('F Y')]) ?></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body"><h2 class="h6 mb-0">Documents by status</h2></div>
            <div class="card-body">
                <?php if ($total === 0): ?>
                    <p class="text-body-secondary small mb-0">No documents yet.</p>
                <?php else: ?>
                    <div class="chart-box chart-box-doughnut"><canvas data-chart="<?= e(json_encode($statusChart, JSON_THROW_ON_ERROR)) ?>" role="img" aria-label="Documents by status chart"></canvas></div>
                <?php endif; ?>
                <table class="table table-sm small mt-3 mb-0">
                    <caption class="visually-hidden">Documents by status</caption>
                    <tbody>
                    <?php foreach ($byStatus as $status => $count): ?>
                        <tr>
                            <th scope="row" class="fw-normal"><?= partial('partials/status-badge', ['status' => $status]) ?></th>
                            <td class="text-end"><a href="<?= e(url('/documents', ['tab' => 'all', 'status' => $status])) ?>"><?= e(number_format($count)) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body"><h2 class="h6 mb-0">Top departments by overdue documents</h2></div>
            <div class="card-body">
                <?php if ($topOverdue === []): ?>
                    <p class="text-body-secondary small mb-0">No department has overdue documents. 👍</p>
                <?php else: ?>
                    <div class="chart-box"><canvas data-chart="<?= e(json_encode($overdueChart, JSON_THROW_ON_ERROR)) ?>" role="img" aria-label="Overdue documents by department chart"></canvas></div>
                    <ol class="small mt-3 mb-0">
                        <?php foreach ($topOverdue as $d): ?>
                            <li><a href="<?= e(url('/documents', ['tab' => 'all', 'flag' => 'overdue', 'current' => $d['id']])) ?>"><?= e($d['name']) ?></a>: <strong><?= e($d['overdue']) ?></strong></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><i class="bi bi-hourglass-split text-warning" aria-hidden="true"></i> In transit for more than <?= e($alertHours) ?> hours</h2>
                <a class="small" href="<?= e(url('/documents', ['tab' => 'all', 'flag' => 'stuck'])) ?>">View all</a>
            </div>
            <?php if ($stuck === []): ?>
                <div class="card-body small text-body-secondary">Every sent document has been received within <?= e($alertHours) ?> hours.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th scope="col">Document</th><th scope="col">Route</th><th scope="col" class="d-none d-md-table-cell">Sent by</th><th scope="col">Waiting</th></tr></thead>
                        <tbody>
                        <?php foreach ($stuck as $d): ?>
                            <tr class="row-stuck">
                                <td>
                                    <a class="font-monospace" href="<?= e(url("/documents/{$d['id']}")) ?>"><?= e($d['tracking_no']) ?></a>
                                    <?php if ($d['priority'] === 'urgent'): ?><span class="badge text-bg-danger">Urgent</span><?php endif; ?>
                                </td>
                                <td class="text-nowrap"><?= e($d['om_from_code']) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i><span class="visually-hidden">to</span> <strong><?= e($d['om_to_code']) ?></strong></td>
                                <td class="d-none d-md-table-cell"><?= e($d['om_from_user']) ?></td>
                                <td class="text-nowrap fw-semibold text-warning-emphasis"><?= e(human_duration(seconds_since($d['om_sent_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body"><h2 class="h6 mb-0"><i class="bi bi-activity" aria-hidden="true"></i> Recent activity</h2></div>
            <?php if ($activity === []): ?>
                <div class="card-body small text-body-secondary">No activity yet.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush small activity-feed">
                    <?php foreach ($activity as $a): ?>
                        <li class="list-group-item d-flex gap-2">
                            <div class="min-w-0 flex-grow-1">
                                <strong><?= e($a['user_name'] ?? 'System') ?></strong>
                                <?= e(DashboardService::activityLabel((string) $a['action'])) ?>
                                <?php if ($a['tracking_no']): ?>
                                    <a class="font-monospace" href="<?= e(url("/documents/{$a['document_id']}")) ?>"><?= e($a['tracking_no']) ?></a>
                                <?php endif; ?>
                            </div>
                            <span class="text-body-secondary text-nowrap"><?= time_ago((string) $a['created_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?= e(asset('js/vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(asset('js/dashboard.js')) ?>"></script>
