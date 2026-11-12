<?php
/**
 * @var array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int} $result
 * @var array{q: string, status: string} $filters
 */
?>
<div class="page-header">
    <div>
        <h1 class="h4 mb-0">Document types</h1>
        <p>Types of documents and how many working days each department has to act.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/admin/document-types/create')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New type</a>
</div>

<form class="card card-body shadow-sm mb-3" method="get" action="<?= e(url('/admin/document-types')) ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-6">
            <label for="q" class="form-label small">Search</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Type name">
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
            <a class="btn btn-outline-secondary" href="<?= e(url('/admin/document-types')) ?>">Reset</a>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th scope="col">Name</th>
                <th scope="col" class="text-center">SLA (working days)</th>
                <th scope="col" class="text-center d-none d-md-table-cell">Documents</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($result['data'] === []): ?>
                <tr><td colspan="5" class="text-center text-body-secondary py-4">No document types found.</td></tr>
            <?php endif; ?>
            <?php foreach ($result['data'] as $t): ?>
                <tr>
                    <td class="fw-semibold"><?= e($t['name']) ?></td>
                    <td class="text-center"><?= e($t['sla_days']) ?></td>
                    <td class="text-center d-none d-md-table-cell"><?= e($t['document_count']) ?></td>
                    <td><?= partial('partials/active-badge', ['active' => (bool) $t['is_active']]) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url("/admin/document-types/{$t['id']}/edit")) ?>" title="Edit">
                            <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edit <?= e($t['name']) ?></span>
                        </a>
                        <?= partial('partials/status-toggle', [
                            'action' => "/admin/document-types/{$t['id']}/status",
                            'active' => (bool) $t['is_active'],
                            'name' => $t['name'],
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
    'path' => '/admin/document-types', 'query' => $filters,
]) ?>
