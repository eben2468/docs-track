<?php
/**
 * Printer-friendly layout (QR labels): no navigation, a Print button hidden when printing.
 *
 * @var string $content
 * @var string|null $title
 */
$appName = setting('app_name');
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $appName : $appName;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/branding/theme', ['v' => substr(md5(setting('primary_color')), 0, 8)])) ?>">
</head>
<body class="print-body">
<div class="print-toolbar d-print-none">
    <button type="button" class="btn btn-primary" data-action="print">
        <i class="bi bi-printer" aria-hidden="true"></i> Print
    </button>
    <button type="button" class="btn btn-outline-secondary" data-action="back">Back</button>
</div>
<main class="print-page">
    <?= $content ?>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
