<?php
/**
 * Pagination links that keep the current query string (filters, sort, tab).
 *
 * @var int $page        Current page (1-based)
 * @var int $perPage     Items per page
 * @var int $total       Total matching items
 * @var string $path     App path the links point to, e.g. "/documents"
 * @var array<string, scalar|null> $query Current query parameters (page is replaced)
 */
$query ??= [];
$perPage = max(1, (int) $perPage);
$total = max(0, (int) $total);
$lastPage = max(1, (int) ceil($total / $perPage));
$page = min(max(1, (int) $page), $lastPage);
$from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to = min($total, $page * $perPage);

$window = 2;
$start = max(1, $page - $window);
$end = min($lastPage, $page + $window);
$link = static fn (int $p): string => url($path, array_merge($query, ['page' => $p]));
?>
<div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-2 mt-3">
    <div class="small text-body-secondary">
        Showing <?= e($from) ?>–<?= e($to) ?> of <?= e(number_format($total)) ?>
    </div>
    <?php if ($lastPage > 1): ?>
        <nav aria-label="Pagination">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($link(max(1, $page - 1))) ?>" aria-label="Previous">&laquo;</a>
                </li>
                <?php if ($start > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= e($link(1)) ?>">1</a></li>
                    <?php if ($start > 2): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $start; $p <= $end; $p++): ?>
                    <li class="page-item<?= $p === $page ? ' active' : '' ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>>
                        <a class="page-link" href="<?= e($link($p)) ?>"><?= e($p) ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($end < $lastPage): ?>
                    <?php if ($end < $lastPage - 1): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?= e($link($lastPage)) ?>"><?= e($lastPage) ?></a></li>
                <?php endif; ?>
                <li class="page-item<?= $page >= $lastPage ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($link(min($lastPage, $page + 1))) ?>" aria-label="Next">&raquo;</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
