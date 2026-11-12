<?php
/**
 * @var int $year
 * @var list<int> $years
 * @var list<array<string, mixed>> $holidays
 */
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h1 class="h4 mb-0">Holidays</h1>
    <a class="btn btn-primary" href="<?= e(url('/admin/holidays/create')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New holiday</a>
</div>

<p class="text-body-secondary small">Holidays are skipped, along with weekends, when due dates are calculated. Islamic holiday dates are announced each year; check them against the official gazette.</p>

<form class="d-flex align-items-center gap-2 mb-3" method="get" action="<?= e(url('/admin/holidays')) ?>">
    <label for="year" class="form-label mb-0">Year</label>
    <select class="form-select form-select-sm w-auto" id="year" name="year" data-auto-submit>
        <?php foreach ($years as $y): ?>
            <option value="<?= e($y) ?>"<?= $y === $year ? ' selected' : '' ?>><?= e($y) ?></option>
        <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-sm btn-outline-primary" type="submit">Show</button></noscript>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Day</th>
                <th scope="col">Name</th>
                <th scope="col" class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($holidays === []): ?>
                <tr><td colspan="4" class="text-center text-body-secondary py-4">No holidays recorded for <?= e($year) ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($holidays as $h):
                $date = new DateTimeImmutable((string) $h['holiday_date']);
                $weekend = (int) $date->format('N') >= 6; ?>
                <tr>
                    <td class="text-nowrap"><?= e($date->format('j M Y')) ?></td>
                    <td><?= e($date->format('l')) ?><?= $weekend ? ' <span class="badge text-bg-light border">Weekend</span>' : '' ?></td>
                    <td><?= e($h['name']) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url("/admin/holidays/{$h['id']}/edit")) ?>" title="Edit">
                            <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edit <?= e($h['name']) ?></span>
                        </a>
                        <form method="post" action="<?= e(url("/admin/holidays/{$h['id']}/delete")) ?>" class="d-inline"
                              data-confirm="Delete the holiday &quot;<?= e($h['name']) ?>&quot; on <?= e($date->format('j M Y')) ?>?"
                              data-confirm-button="Delete" data-confirm-variant="danger">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">Delete <?= e($h['name']) ?></span>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
