<?php
/**
 * Flash messages queued with flash() or Response::with().
 */
$icons = [
    'success' => 'check-circle',
    'danger' => 'exclamation-octagon',
    'warning' => 'exclamation-triangle',
    'info' => 'info-circle',
];
foreach (\Core\Session::messages() as $flashMessage):
    $type = array_key_exists($flashMessage['type'], $icons) ? $flashMessage['type'] : 'info';
?>
    <div class="alert alert-<?= e($type) ?> alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-<?= e($icons[$type]) ?> flex-shrink-0" aria-hidden="true"></i>
        <div><?= e($flashMessage['message']) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>
