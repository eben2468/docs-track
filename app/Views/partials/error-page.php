<?php
/**
 * Shared body for the pages in errors/.
 *
 * @var int $status
 * @var string $title
 * @var string $icon            Bootstrap Icons name
 * @var string $defaultMessage  Shown when no specific message was given
 * @var string|null $message
 * @var Throwable|null $exception Only passed when APP_DEBUG=true
 */
$message = isset($message) && $message !== '' ? $message : $defaultMessage;
?>
<div class="error-page text-center mx-auto">
    <div class="error-icon"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></div>
    <p class="error-code"><?= e($status) ?></p>
    <h1 class="h3"><?= e($title) ?></h1>
    <p class="text-body-secondary"><?= e($message) ?></p>
    <a class="btn btn-primary mt-2" href="<?= e(url('/')) ?>"><i class="bi bi-house" aria-hidden="true"></i> Go to home page</a>
</div>

<?php if (isset($exception) && $exception instanceof Throwable): ?>
    <div class="card border-danger mt-5 text-start">
        <div class="card-header bg-danger-subtle">
            <strong><?= e($exception::class) ?></strong> (debug mode only)
        </div>
        <div class="card-body">
            <p class="mb-2"><?= e($exception->getMessage()) ?></p>
            <p class="small text-body-secondary mb-3"><?= e($exception->getFile()) ?>:<?= e($exception->getLine()) ?></p>
            <pre class="debug-trace small mb-0"><?= e($exception->getTraceAsString()) ?></pre>
        </div>
    </div>
<?php endif; ?>
