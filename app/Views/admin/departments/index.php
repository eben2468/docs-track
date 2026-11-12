<?php
/**
 * @var array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int} $result
 * @var array{q: string, status: string} $filters
 */
?>
<div class="page-header">
    <div>
        <h1 class="h4 mb-0">Departments</h1>
        <p>Offices that send and receive documents, and their heads.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/admin/departments/create')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New department</a>
</div>

<form class="card card-body shadow-sm mb-3" method="get" action="<?= e(url('/admin/departments')) ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-6">
            <label for="q" class="form-label small">Search</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name or code">
        </div>
        <div class="col-md-3">
            <label for="status" class="form-label small">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Active</option>
                <option value="inactive"<?= $filters['status'] === 'inactive' ? ' selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-outline-primary flex-grow-1" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('/admin/departments')) ?>">Reset</a>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th scope="col">Code</th>
                <th scope="col">Name</th>
                <th scope="col" class="d-none d-lg-table-cell">Parent</th>
                <th scope="col" class="d-none d-md-table-cell">Head of department</th>
                <th scope="col" class="text-center">Users</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($result['data'] === []): ?>
                <tr><td colspan="7" class="text-center text-body-secondary py-4">No departments found.</td></tr>
            <?php endif; ?>
            <?php foreach ($result['data'] as $d): ?>
                <tr>
                    <td><span class="badge text-bg-light border font-monospace"><?= e($d['code']) ?></span></td>
                    <td class="fw-semibold"><?= e($d['name']) ?></td>
                    <td class="d-none d-lg-table-cell"><?= e($d['parent_name'] ?? '—') ?></td>
                    <td class="d-none d-md-table-cell"><?= e($d['head_name'] ?? '—') ?></td>
                    <td class="text-center"><?= e($d['active_users']) ?></td>
                    <td><?= partial('partials/active-badge', ['active' => (bool) $d['is_active']]) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url("/admin/departments/{$d['id']}/edit")) ?>" title="Edit">
                            <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edit <?= e($d['name']) ?></span>
                        </a>
                        <?= partial('partials/status-toggle', [
                            'action' => "/admin/departments/{$d['id']}/status",
                            'active' => (bool) $d['is_active'],
                            'name' => $d['name'],
                        ]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= partial('partials/pagination', [
    'page' => $result['page'], 'perPage' => $result['per_page'], 'total' => $result['total'],
    'path' => '/admin/departments', 'query' => $filters,
]) ?>
