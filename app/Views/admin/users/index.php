<?php
/**
 * @var array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int} $result
 * @var array{q: string, department: string, role: string, status: string} $filters
 * @var list<array<string, mixed>> $departments
 */
$selfId = (int) (auth()['id'] ?? 0);
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h1 class="h4 mb-0">Users</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('/admin/users/import')) ?>"><i class="bi bi-upload" aria-hidden="true"></i> Import CSV</a>
        <a class="btn btn-primary" href="<?= e(url('/admin/users/create')) ?>"><i class="bi bi-person-plus" aria-hidden="true"></i> New user</a>
    </div>
</div>

<form class="card card-body shadow-sm mb-3" method="get" action="<?= e(url('/admin/users')) ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label for="q" class="form-label small">Search</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, email or staff ID">
        </div>
        <div class="col-md-3">
            <label for="department" class="form-label small">Department</label>
            <select class="form-select" id="department" name="department">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= e($d['id']) ?>"<?= $filters['department'] === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="role" class="form-label small">Role</label>
            <select class="form-select" id="role" name="role">
                <option value="">All</option>
                <?php foreach (['admin', 'hod', 'staff'] as $r): ?>
                    <option value="<?= $r ?>"<?= $filters['role'] === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-1">
            <label for="status" class="form-label small">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'locked' => 'Locked'] as $k => $label): ?>
                    <option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-outline-primary flex-grow-1" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('/admin/users')) ?>" title="Reset filters"><i class="bi bi-x-lg" aria-hidden="true"></i><span class="visually-hidden">Reset</span></a>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th scope="col">Name</th>
                <th scope="col" class="d-none d-md-table-cell">Staff ID</th>
                <th scope="col" class="d-none d-lg-table-cell">Department</th>
                <th scope="col">Role</th>
                <th scope="col" class="d-none d-xl-table-cell">Last sign-in</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($result['data'] === []): ?>
                <tr><td colspan="7" class="text-center text-body-secondary py-4">No users found.</td></tr>
            <?php endif; ?>
            <?php foreach ($result['data'] as $u):
                $locked = $u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time(); ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($u['full_name']) ?></div>
                        <div class="small text-body-secondary"><?= e($u['email']) ?></div>
                    </td>
                    <td class="d-none d-md-table-cell font-monospace small"><?= e($u['staff_id']) ?></td>
                    <td class="d-none d-lg-table-cell"><?= e($u['department_name']) ?></td>
                    <td><?= e(role_label($u['role'])) ?></td>
                    <td class="d-none d-xl-table-cell small"><?= e(format_datetime($u['last_login_at'])) ?></td>
                    <td>
                        <?= partial('partials/active-badge', ['active' => (bool) $u['is_active']]) ?>
                        <?php if ($locked): ?>
                            <span class="badge text-bg-warning" title="Locked until <?= e(format_datetime($u['locked_until'], 'H:i')) ?>">Locked</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if ($locked): ?>
                            <form method="post" action="<?= e(url("/admin/users/{$u['id']}/unlock")) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Unlock">
                                    <i class="bi bi-unlock" aria-hidden="true"></i><span class="visually-hidden">Unlock <?= e($u['full_name']) ?></span>
                                </button>
                            </form>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url("/admin/users/{$u['id']}/edit")) ?>" title="Edit">
                            <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edit <?= e($u['full_name']) ?></span>
                        </a>
                        <?php if ((int) $u['id'] !== $selfId): ?>
                            <?= partial('partials/status-toggle', [
                                'action' => "/admin/users/{$u['id']}/status",
                                'active' => (bool) $u['is_active'],
                                'name' => $u['full_name'],
                            ]) ?>
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
    'path' => '/admin/users', 'query' => $filters,
]) ?>
