<?php
/**
 * Dashboard stat card linking to the matching filtered list.
 *
 * @var string $label
 * @var int $value
 * @var string $icon Bootstrap Icons name
 * @var string $href App path with query
 * @var string $tone primary|warning|danger|success|secondary
 * @var string|null $hint Small text under the number
 */
$hint ??= null;
?>
<a class="stat-card card shadow-sm h-100 text-decoration-none stat-<?= e($tone) ?>" href="<?= e(url($href)) ?>">
    <div class="card-body d-flex align-items-center gap-3">
        <span class="stat-icon" aria-hidden="true"><i class="bi bi-<?= e($icon) ?>"></i></span>
        <div class="min-w-0">
            <div class="stat-value"><?= e(number_format($value)) ?></div>
            <div class="stat-label"><?= e($label) ?></div>
            <?php if ($hint): ?><div class="stat-hint"><?= e($hint) ?></div><?php endif; ?>
        </div>
    </div>
</a>
