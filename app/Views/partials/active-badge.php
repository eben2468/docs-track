<?php
/** @var bool $active */
?>
<?php if ($active): ?>
    <span class="badge text-bg-success-subtle text-success-emphasis border border-success-subtle">Active</span>
<?php else: ?>
    <span class="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Inactive</span>
<?php endif; ?>
