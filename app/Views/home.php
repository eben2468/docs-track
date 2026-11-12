<?php
/**
 * Temporary home page until the dashboards are built.
 *
 * @var list<array<string, mixed>> $incoming Movements awaiting the user's department
 * @var array<string, array{0: bool, 1: string}> $checks
 */
$user = auth();
?>
<div class="mb-4">
    <h1 class="h4 mb-1">Welcome, <?= e($user['full_name'] ?? '') ?></h1>
    <p class="text-body-secondary mb-0">Full document lists and dashboards will appear here in upcoming releases.</p>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-body d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0"><i class="bi bi-inbox" aria-hidden="true"></i> Awaiting your department's receipt</h2>
        <span class="badge <?= $incoming === [] ? 'text-bg-light border' : 'text-bg-warning' ?>"><?= count($incoming) ?></span>
    </div>
    <?php if ($incoming === []): ?>
        <div class="card-body small text-body-secondary">Nothing is waiting to be received.</div>
    <?php else: ?>
        <form method="post" action="<?= e(url('/documents/receive-bulk')) ?>">
            <?= csrf_field() ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                    <tr>
                        <th scope="col" class="text-center">
                            <input class="form-check-input" type="checkbox" data-check-all="document_ids[]" aria-label="Select all">
                        </th>
                        <th scope="col">Tracking no.</th>
                        <th scope="col">Title</th>
                        <th scope="col" class="d-none d-md-table-cell">From</th>
                        <th scope="col" class="d-none d-lg-table-cell">Sent</th>
                        <th scope="col" class="d-none d-lg-table-cell">Note</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($incoming as $m): ?>
                        <tr>
                            <td class="text-center">
                                <input class="form-check-input" type="checkbox" name="document_ids[]" value="<?= e($m['document_id']) ?>"
                                       aria-label="Select <?= e($m['tracking_no']) ?>">
                            </td>
                            <td class="font-monospace text-nowrap">
                                <a href="<?= e(url("/documents/{$m['document_id']}")) ?>"><?= e($m['tracking_no']) ?></a>
                                <?php if ($m['priority'] === 'urgent'): ?><span class="badge text-bg-danger">Urgent</span><?php endif; ?>
                            </td>
                            <td><?= e($m['title']) ?><?php if ((int) $m['to_user_id'] === (int) $user['id']): ?> <span class="badge text-bg-info">For you</span><?php endif; ?></td>
                            <td class="d-none d-md-table-cell"><?= e($m['from_department_name']) ?><div class="text-body-secondary"><?= e($m['from_user_name']) ?></div></td>
                            <td class="d-none d-lg-table-cell text-nowrap"><?= e(format_datetime($m['sent_at'])) ?></td>
                            <td class="d-none d-lg-table-cell fst-italic"><?= e($m['note']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-body d-flex flex-wrap gap-2 align-items-center">
                <label for="bulk_note" class="visually-hidden">Receipt note</label>
                <input type="text" class="form-control form-control-sm w-auto flex-grow-1" id="bulk_note" name="receipt_note"
                       maxlength="500" placeholder="Receipt note (optional, applies to all selected)">
                <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receive selected</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-body"><h2 class="h6 mb-0">System check</h2></div>
    <div class="list-group list-group-flush">
        <?php foreach ($checks as $label => [$ok, $detail]): ?>
            <div class="list-group-item d-flex align-items-center gap-3 py-2">
                <i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>" aria-hidden="true"></i>
                <div class="flex-grow-1 small"><span class="fw-semibold"><?= e($label) ?>:</span> <?= e($detail) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
