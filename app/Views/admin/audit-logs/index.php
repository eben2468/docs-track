<?php
/**
 * Read-only audit trail (FR-13).
 *
 * @var array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int} $result
 * @var array<string, string> $filters
 * @var list<string> $actions
 * @var list<string> $groups
 */
use App\Services\DashboardService;

$actionTone = static fn (string $action): string => match (true) {
    str_starts_with($action, 'login.failed'), str_starts_with($action, 'login.locked'), str_starts_with($action, 'login.throttled') => 'text-bg-danger',
    str_starts_with($action, 'login'), $action === 'logout' => 'text-bg-light border',
    str_starts_with($action, 'document.') => 'text-bg-primary',
    str_starts_with($action, 'attachment.') => 'text-bg-info',
    str_starts_with($action, 'user.'), str_starts_with($action, 'department.'), str_starts_with($action, 'settings.') => 'text-bg-warning',
    default => 'text-bg-secondary',
};
?>
<div class="page-header">
    <div>
        <h1 class="h4 mb-0">Audit logs</h1>
        <p>Every sign-in, document action and admin change, permanently recorded.</p>
    </div>
    <span class="small text-body-secondary"><i class="bi bi-lock" aria-hidden="true"></i> Read-only: entries can never be edited or deleted.</span>
</div>

<form class="card card-body shadow-sm mb-3" method="get" action="<?= e(url('/admin/audit-logs')) ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-6 col-xl-4">
            <label for="action" class="form-label small">Action</label>
            <select class="form-select" id="action" name="action">
                <option value="">All actions</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= e($g) ?>.*"<?= ($filters['action'] ?? '') === "$g.*" ? ' selected' : '' ?>>All <?= e($g) ?> actions</option>
                <?php endforeach; ?>
                <option disabled>──────────</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>"<?= ($filters['action'] ?? '') === $a ? ' selected' : '' ?>><?= e($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6 col-xl-4">
            <label for="user" class="form-label small">User</label>
            <input type="search" class="form-control" id="user" name="user" value="<?= e($filters['user'] ?? '') ?>" placeholder="Name, email or staff ID">
        </div>
        <div class="col-md-6 col-xl-4">
            <label for="document" class="form-label small">Tracking no.</label>
            <input type="search" class="form-control font-monospace" id="document" name="document" value="<?= e($filters['document'] ?? '') ?>">
        </div>
        <div class="col-md-6 col-xl-4">
            <label for="ip" class="form-label small">IP address</label>
            <input type="search" class="form-control" id="ip" name="ip" value="<?= e($filters['ip'] ?? '') ?>">
        </div>
        <div class="col-6 col-xl-4">
            <label for="from" class="form-label small">From</label>
            <input type="date" class="form-control" id="from" name="from" value="<?= e($filters['from'] ?? '') ?>">
        </div>
        <div class="col-6 col-xl-4">
            <label for="to" class="form-label small">To</label>
            <input type="date" class="form-control" id="to" name="to" value="<?= e($filters['to'] ?? '') ?>">
        </div>
        <div class="col-12 d-flex gap-2 justify-content-end">
            <a class="btn btn-outline-secondary" href="<?= e(url('/admin/audit-logs')) ?>">Reset</a>
            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 small">
            <thead class="table-light">
            <tr>
                <th scope="col">When</th>
                <th scope="col">User</th>
                <th scope="col">Action</th>
                <th scope="col">Document</th>
                <th scope="col" class="d-none d-lg-table-cell">IP</th>
                <th scope="col">Details</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($result['data'] === []): ?>
                <tr><td colspan="6" class="text-center text-body-secondary py-4">No log entries match.</td></tr>
            <?php endif; ?>
            <?php foreach ($result['data'] as $log):
                $details = $log['details'] !== null ? json_decode((string) $log['details'], true) : null; ?>
                <tr>
                    <td class="text-nowrap" title="<?= e(format_datetime($log['created_at'], 'l j F Y, H:i:s')) ?>"><?= e(format_datetime($log['created_at'], 'j M Y H:i:s')) ?></td>
                    <td>
                        <?php if ($log['user_name']): ?>
                            <?= e($log['user_name']) ?><div class="text-body-secondary font-monospace"><?= e($log['staff_id']) ?></div>
                        <?php else: ?>
                            <span class="text-body-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $actionTone((string) $log['action']) ?> font-monospace"><?= e($log['action']) ?></span>
                        <div class="text-body-secondary"><?= e(DashboardService::activityLabel((string) $log['action'])) ?></div>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($log['tracking_no']): ?>
                            <a class="font-monospace" href="<?= e(url('/documents/' . $log['document_id'])) ?>"><?= e($log['tracking_no']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="d-none d-lg-table-cell font-monospace" title="<?= e($log['user_agent'] ?? '') ?>"><?= e($log['ip_address'] ?? '—') ?></td>
                    <td class="audit-details">
                        <?php if (is_array($details) && $details !== []): ?>
                            <details>
                                <summary class="text-primary">View</summary>
                                <pre class="mb-0 mt-1"><?= e(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                            </details>
                        <?php else: ?>
                            <span class="text-body-secondary">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= partial('partials/pagination', [
    'page' => $result['page'], 'perPage' => $result['per_page'], 'total' => $result['total'],
    'path' => '/admin/audit-logs', 'query' => $filters,
]) ?>
