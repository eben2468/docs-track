<?php
/**
 * Document status pill (spec §10.3 colours: Draft grey, In Transit amber, Received blue,
 * Completed green, Cancelled dark).
 *
 * @var string $status draft|in_transit|received|completed|cancelled
 */
$label = [
    'draft' => 'Draft',
    'in_transit' => 'In Transit',
    'received' => 'Received',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
][$status] ?? ucfirst($status);
$key = in_array($status, ['draft', 'in_transit', 'received', 'completed', 'cancelled'], true) ? $status : 'draft';
?>
<span class="status-pill status-<?= $key ?>"><?= e($label) ?></span>
