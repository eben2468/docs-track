<?php
/**
 * CSV user import with a row-by-row report after a failed attempt.
 *
 * @var list<array{line: int, data: array<string, string>, errors: list<string>}>|null $report
 */
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/admin/users')) ?>">Users</a></li>
        <li class="breadcrumb-item active" aria-current="page">Import</li>
    </ol>
</nav>
<h1 class="h4 mb-3">Import users from CSV</h1>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e(url('/admin/users/import')) ?>" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="file" class="form-label">CSV file <span class="text-danger">*</span></label>
                        <input type="file" class="form-control<?= invalid('file') ?>" id="file" name="file" accept=".csv,text/csv" required>
                        <?= feedback('file') ?>
                        <div class="form-text">Maximum 1 MB and <?= e(\App\Services\UserImportService::MAX_ROWS) ?> rows.</div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload" aria-hidden="true"></i> Validate and import</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body small">
                <h2 class="h6">File format</h2>
                <p>First row must contain these columns (any order):</p>
                <p><code><?= e(implode(', ', \App\Services\UserImportService::HEADERS)) ?></code></p>
                <ul class="mb-3">
                    <li><code>department_code</code> must match an active department (e.g. REG).</li>
                    <li><code>role</code> is <code>staff</code>, <code>hod</code> or <code>admin</code> (blank = staff).</li>
                    <li>If any row has a problem, <strong>nothing is imported</strong>; you get a report to fix the file.</li>
                    <li><code>username</code> is what the user signs in with: letters, numbers, dots, hyphens and underscores.</li>
                    <li>Each imported user is emailed their username and how to sign in.</li>
                </ul>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/users/import/template')) ?>">
                    <i class="bi bi-download" aria-hidden="true"></i> Download template
                </a>
            </div>
        </div>
    </div>
</div>

<?php if ($report !== null): ?>
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-body"><h2 class="h6 mb-0">Validation report</h2></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th scope="col">Line</th>
                    <th scope="col">Staff ID</th>
                    <th scope="col">Username</th>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Dept</th>
                    <th scope="col">Role</th>
                    <th scope="col">Result</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($report as $row): ?>
                    <tr class="<?= $row['errors'] === [] ? '' : 'table-danger' ?>">
                        <td><?= e($row['line']) ?></td>
                        <td class="font-monospace"><?= e($row['data']['staff_id']) ?></td>
                        <td class="font-monospace"><?= e($row['data']['username']) ?></td>
                        <td><?= e($row['data']['full_name']) ?></td>
                        <td><?= e($row['data']['email']) ?></td>
                        <td><?= e($row['data']['department_code']) ?></td>
                        <td><?= e($row['data']['role']) ?></td>
                        <td>
                            <?php if ($row['errors'] === []): ?>
                                <span class="text-success"><i class="bi bi-check-circle" aria-hidden="true"></i> OK</span>
                            <?php else: ?>
                                <ul class="mb-0 ps-3 small text-danger-emphasis">
                                    <?php foreach ($row['errors'] as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
