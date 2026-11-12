<?php
/**
 * Document status badge (spec §10.3 colours).
 *
 * @var string $status draft|in_transit|received|completed|cancelled
 */
[$label, $class] = match ($status) {
    'draft' => ['Draft', 'text-bg-secondary'],
    'in_transit' => ['In Transit', 'text-bg-warning'],
    'received' => ['Received', 'text-bg-primary'],
    'completed' => ['Completed', 'text-bg-success'],
    'cancelled' => ['Cancelled', 'text-bg-dark'],
    default => [ucfirst($status), 'text-bg-light'],
};
?>
<span class="badge <?= $class ?>"><?= e($label) ?></span>
