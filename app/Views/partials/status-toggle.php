<?php
/**
 * Activate / deactivate button (POST with confirmation).
 *
 * @var string $action App path of the status endpoint, e.g. "/admin/users/5/status"
 * @var bool $active Current state
 * @var string $name What is being toggled, for the confirmation text
 */
?>
<form method="post" action="<?= e(url($action)) ?>" class="d-inline"
      data-confirm="<?= e(($active ? 'Deactivate ' : 'Activate ') . $name . '?' . ($active ? ' Its history is kept and it can be reactivated later.' : '')) ?>"
      data-confirm-button="<?= $active ? 'Deactivate' : 'Activate' ?>"
      data-confirm-variant="<?= $active ? 'danger' : 'primary' ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
    <button type="submit" class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>"
            title="<?= $active ? 'Deactivate' : 'Activate' ?>">
        <i class="bi <?= $active ? 'bi-pause-circle' : 'bi-play-circle' ?>" aria-hidden="true"></i>
        <span class="visually-hidden"><?= $active ? 'Deactivate' : 'Activate' ?> <?= e($name) ?></span>
    </button>
</form>
