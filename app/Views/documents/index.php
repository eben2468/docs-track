<?php
/**
 * Document lists (FR-12): tabs, filters, sortable columns, pagination, CSV export,
 * bulk receive on Incoming.
 *
 * @var string $tab
 * @var array<string, string> $filters
 * @var string $sort
 * @var string $dir
 * @var array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int} $result
 * @var array<string, int> $counts incoming, with_us, outgoing
 * @var list<array<string, mixed>> $types
 * @var list<array<string, mixed>> $departments
 * @var bool $isAdmin
 * @var int $userId
 */
use App\Controllers\DocumentListController;

$tabs = [
    'incoming' => ['inbox', $counts['incoming'] ?? 0],
    'with-us' => ['folder2-open', $counts['with_us'] ?? 0],
    'outgoing' => ['send', $counts['outgoing'] ?? 0],
    'registered' => ['journal-text', null],
    'completed' => ['check2-square', null],
    ($isAdmin ? 'all' : 'all-accessible') => ['collection', null],
];
$query = array_merge($filters, ['tab' => $tab, 'sort' => $sort, 'dir' => $dir]);
$sortLink = static function (string $key, string $label) use ($query, $sort, $dir): string {
    $active = $sort === $key;
    $nextDir = $active && $dir === 'asc' ? 'desc' : 'asc';
    $icon = $active ? ($dir === 'asc' ? ' <i class="bi bi-caret-up-fill" aria-hidden="true"></i>' : ' <i class="bi bi-caret-down-fill" aria-hidden="true"></i>') : '';

    return '<a class="sort-link' . ($active ? ' active' : '') . '" href="' . e(url('/documents', array_merge($query, ['sort' => $key, 'dir' => $nextDir, 'page' => null]))) . '"'
        . ($active ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>' . e($label) . $icon . '</a>';
};
[$dateKey, $dateLabel] = match ($tab) {
    'incoming', 'outgoing' => ['sent', 'Sent'],
    'with-us' => ['received', 'Received'],
    default => ['created', 'Registered'],
};
$filtersOpen = array_diff_key($filters, ['q' => true]) !== [];
$statusLabels = ['draft' => 'Draft', 'in_transit' => 'In transit', 'received' => 'Received', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$isIncoming = $tab === 'incoming';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h1 class="h4 mb-0"><?= e(DocumentListController::tabLabel($tab)) ?></h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/documents/export', $query)) ?>">
            <i class="bi bi-filetype-csv" aria-hidden="true"></i> Export CSV
        </a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/documents/create')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Register</a>
    </div>
</div>

<ul class="nav nav-pills doc-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
    <?php foreach ($tabs as $key => [$icon, $count]): ?>
        <li class="nav-item">
            <a class="nav-link text-nowrap<?= $tab === $key ? ' active' : '' ?>" href="<?= e(url('/documents', ['tab' => $key])) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
                <i class="bi bi-<?= $icon ?>" aria-hidden="true"></i> <?= e(DocumentListController::tabLabel($key)) ?>
                <?php if ($count !== null && $count > 0): ?><span class="badge rounded-pill <?= $tab === $key ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= e($count) ?></span><?php endif; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<form class="card card-body shadow-sm mb-3" method="get" action="<?= e(url('/documents')) ?>">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-6 col-lg-5">
            <label for="q" class="form-label small">Search</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Tracking no., reference or keywords">
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label for="status" class="form-label small">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any</option>
                <?php foreach ($statusLabels as $k => $label): ?>
                    <option value="<?= $k ?>"<?= ($filters['status'] ?? '') === $k ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label for="priority" class="form-label small">Priority</label>
            <select class="form-select" id="priority" name="priority">
                <option value="">Any</option>
                <option value="urgent"<?= ($filters['priority'] ?? '') === 'urgent' ? ' selected' : '' ?>>Urgent</option>
                <option value="normal"<?= ($filters['priority'] ?? '') === 'normal' ? ' selected' : '' ?>>Normal</option>
            </select>
        </div>
        <div class="col-lg-3 d-flex gap-2">
            <button class="btn btn-outline-primary flex-grow-1" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
            <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#moreFilters"
                    aria-expanded="<?= $filtersOpen ? 'true' : 'false' ?>" aria-controls="moreFilters" title="More filters">
                <i class="bi bi-sliders" aria-hidden="true"></i><span class="visually-hidden">More filters</span>
            </button>
            <a class="btn btn-outline-secondary" href="<?= e(url('/documents', ['tab' => $tab])) ?>" title="Clear filters">
                <i class="bi bi-x-lg" aria-hidden="true"></i><span class="visually-hidden">Clear filters</span>
            </a>
        </div>
    </div>
    <div class="collapse<?= $filtersOpen ? ' show' : '' ?>" id="moreFilters">
        <div class="row g-2 mt-1">
            <div class="col-md-6 col-lg-3">
                <label for="type" class="form-label small">Document type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">Any</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= e($t['id']) ?>"<?= ($filters['type'] ?? '') === (string) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php foreach (['origin' => 'Origin department', 'current' => 'Current department'] as $key => $label): ?>
                <div class="col-md-6 col-lg-3">
                    <label for="<?= $key ?>" class="form-label small"><?= $label ?></label>
                    <select class="form-select" id="<?= $key ?>" name="<?= $key ?>">
                        <option value="">Any</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= e($d['id']) ?>"<?= ($filters[$key] ?? '') === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?> (<?= e($d['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <div class="col-6 col-lg-3">
                <label for="from" class="form-label small">Registered from</label>
                <input type="date" class="form-control" id="from" name="from" value="<?= e($filters['from'] ?? '') ?>">
            </div>
            <div class="col-6 col-lg-3">
                <label for="to" class="form-label small">to</label>
                <input type="date" class="form-control" id="to" name="to" value="<?= e($filters['to'] ?? '') ?>">
            </div>
        </div>
    </div>
</form>

<?php if ($isIncoming && $result['data'] !== []): ?>
<form method="post" action="<?= e(url('/documents/receive-bulk')) ?>">
    <?= csrf_field() ?>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 doc-table">
            <thead class="table-light">
            <tr>
                <?php if ($isIncoming): ?>
                    <th scope="col" class="text-center">
                        <input class="form-check-input" type="checkbox" data-check-all="document_ids[]" aria-label="Select all on this page">
                    </th>
                <?php endif; ?>
                <th scope="col"><?= $sortLink('tracking', 'Tracking no.') ?></th>
                <th scope="col"><?= $sortLink('title', 'Title') ?></th>
                <th scope="col" class="d-none d-md-table-cell"><?= $sortLink('status', 'Status') ?></th>
                <th scope="col" class="d-none d-lg-table-cell"><?= $sortLink('current', 'Location') ?></th>
                <th scope="col" class="d-none d-xl-table-cell"><?= $sortLink('origin', 'Origin') ?></th>
                <th scope="col" class="d-none d-sm-table-cell"><?= $sortLink($dateKey, $dateLabel) ?></th>
                <th scope="col" class="d-none d-md-table-cell"><?= $sortLink('due', 'Due') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($result['data'] === []): ?>
                <tr><td colspan="8" class="text-center text-body-secondary py-5">
                    <i class="bi bi-inbox fs-3 d-block mb-2" aria-hidden="true"></i>No documents match.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($result['data'] as $r):
                $overdue = (int) $r['is_overdue'] === 1;
                $stuck = (int) $r['is_stuck'] === 1;
                $inTransit = $r['status'] === 'in_transit';
                $due = $inTransit ? $r['om_due_date'] : ($r['status'] === 'received' ? $r['lr_due_date'] : null);
                $date = match ($dateKey) {
                    'sent' => $r['om_sent_at'],
                    'received' => $r['lr_received_at'],
                    default => $r['created_at'],
                };
                $rowClass = $overdue ? 'row-overdue' : ($stuck ? 'row-stuck' : ''); ?>
                <tr class="<?= $rowClass ?>">
                    <?php if ($isIncoming): ?>
                        <td class="text-center">
                            <input class="form-check-input" type="checkbox" name="document_ids[]" value="<?= e($r['id']) ?>" aria-label="Select <?= e($r['tracking_no']) ?>">
                        </td>
                    <?php endif; ?>
                    <td class="text-nowrap">
                        <a class="font-monospace fw-semibold" href="<?= e(url("/documents/{$r['id']}")) ?>"><?= e($r['tracking_no']) ?></a>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            <?php if ($r['priority'] === 'urgent'): ?><span class="badge text-bg-danger">Urgent</span><?php endif; ?>
                            <?php if ($overdue): ?><span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"><i class="bi bi-alarm" aria-hidden="true"></i> Overdue</span><?php endif; ?>
                            <?php if ($stuck): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Not received</span><?php endif; ?>
                            <span class="d-md-none"><?= partial('partials/status-badge', ['status' => $r['status']]) ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if ($r['confidentiality'] === 'confidential'): ?><i class="bi bi-lock-fill text-body-secondary" title="Confidential" aria-label="Confidential"></i><?php endif; ?>
                        <?= e($r['title']) ?>
                        <div class="small text-body-secondary"><?= e($r['type_name']) ?><?php if ($isIncoming && (int) $r['om_to_user_id'] === $userId): ?> · <span class="badge text-bg-info">For you</span><?php endif; ?></div>
                        <?php if ($isIncoming && $r['om_note']): ?><div class="small fst-italic">“<?= e($r['om_note']) ?>” — <?= e($r['om_from_user']) ?>, <?= e($r['om_from_code']) ?></div><?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell"><?= partial('partials/status-badge', ['status' => $r['status']]) ?></td>
                    <td class="d-none d-lg-table-cell small">
                        <?php if ($inTransit): ?>
                            <span class="text-nowrap"><?= e($r['om_from_code']) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i><span class="visually-hidden">to</span> <strong><?= e($r['om_to_code']) ?></strong></span>
                        <?php else: ?>
                            <strong><?= e($r['current_code']) ?></strong><?php if ($r['holder_name'] && $r['status'] === 'received'): ?><div class="text-body-secondary"><?= e($r['holder_name']) ?></div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-xl-table-cell small"><?= e($r['origin_code']) ?></td>
                    <td class="d-none d-sm-table-cell small text-nowrap"><?= time_ago($date) ?></td>
                    <td class="d-none d-md-table-cell small text-nowrap<?= $overdue ? ' text-danger fw-semibold' : '' ?>"><?= e($due ? format_datetime($due, 'j M Y') : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($isIncoming && $result['data'] !== []): ?>
        <div class="card-footer bg-body d-flex flex-wrap gap-2 align-items-center">
            <label for="bulk_note" class="visually-hidden">Receipt note</label>
            <input type="text" class="form-control form-control-sm w-auto flex-grow-1" id="bulk_note" name="receipt_note"
                   maxlength="500" placeholder="Receipt note (optional, applies to all selected)">
            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receive selected</button>
        </div>
    <?php endif; ?>
</div>

<?php if ($isIncoming && $result['data'] !== []): ?>
</form>
<?php endif; ?>

<?= partial('partials/pagination', [
    'page' => $result['page'], 'perPage' => $result['per_page'], 'total' => $result['total'],
    'path' => '/documents', 'query' => $query,
]) ?>
<p class="small text-body-secondary mt-2 mb-0">
    <span class="legend-swatch row-overdue" aria-hidden="true"></span> Overdue at the holder ·
    <span class="legend-swatch row-stuck" aria-hidden="true"></span> Not received within <?= e(setting('unreceived_alert_hours')) ?> hours
</p>
