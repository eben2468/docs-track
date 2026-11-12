<?php
/**
 * Printable QR label (spec §5.2). Two sizes: A4 single label, or a 100 × 62 mm sticker.
 * The title is left off confidential documents.
 *
 * @var array<string, mixed> $document
 * @var string $size "a4" or "sticker"
 * @var string $qr data: URI of the QR code (SVG)
 * @var string $trackingUrl
 */
$id = (int) $document['id'];
$confidential = $document['confidentiality'] === 'confidential';
?>
<div class="label-size-switch d-print-none" role="group" aria-label="Label size">
    <a class="btn btn-sm <?= $size === 'a4' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= e(url("/documents/$id/label")) ?>">A4 sheet</a>
    <a class="btn btn-sm <?= $size === 'sticker' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= e(url("/documents/$id/label", ['size' => 'sticker'])) ?>">Sticker (100 × 62 mm)</a>
</div>

<div class="dts-label dts-label-<?= e($size) ?>">
    <div class="dts-label-qr">
        <img src="<?= e($qr) ?>" alt="QR code for <?= e($document['tracking_no']) ?>">
    </div>
    <div class="dts-label-text">
        <div class="dts-label-org"><?= e(setting('app_name')) ?></div>
        <div class="dts-label-tracking"><?= e($document['tracking_no']) ?></div>
        <?php if ($confidential): ?>
            <div class="dts-label-title dts-label-confidential">CONFIDENTIAL</div>
        <?php else: ?>
            <div class="dts-label-title"><?= e($document['title']) ?></div>
        <?php endif; ?>
        <dl class="dts-label-meta">
            <dt>Origin</dt><dd><?= e($document['origin_department_name']) ?> (<?= e($document['origin_department_code']) ?>)</dd>
            <dt>Registered</dt><dd><?= e(format_datetime($document['created_at'], 'j M Y')) ?></dd>
        </dl>
        <div class="dts-label-hint">Scan to track · <?= e(preg_replace('#^https?://#', '', $trackingUrl)) ?></div>
    </div>
</div>
