<?php
/**
 * Vertical movement timeline, newest first (FR-8).
 *
 * @var array<string, mixed> $document
 * @var list<array<string, mixed>> $history Movements, newest first (with names)
 * @var int $alertHours Unreceived alert threshold
 */
$today = now()->format('Y-m-d');
$actionMeta = [
    'send' => ['Sent', 'send'],
    'forward' => ['Forwarded', 'arrow-right-circle'],
    'return' => ['Returned', 'arrow-return-left'],
];
$methodMeta = [
    'click' => ['hand-index-thumb', 'clicked Receive'],
    'qr' => ['qr-code-scan', 'scanned the QR label'],
    'bulk' => ['check2-all', 'bulk receive'],
];
$currentId = null;
foreach ($history as $m) {
    if ($m['status'] === 'received') {
        $currentId = (int) $m['id'];
        break;
    }
}
?>
<?php if ($history === []): ?>
    <p class="text-body-secondary small mb-0">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        Not sent anywhere yet. Registered by <?= e($document['created_by_name']) ?> <?= time_ago($document['created_at']) ?>.
    </p>
<?php else: ?>
    <ol class="timeline">
        <?php foreach ($history as $m):
            [$actionLabel, $icon] = $actionMeta[$m['action']];
            $open = $m['status'] === 'in_transit';
            $recalled = $m['status'] === 'recalled';
            $isCurrent = (int) $m['id'] === $currentId && $document['status'] === 'received';
            $overdue = $m['due_date'] !== null && $m['due_date'] < $today && ($open || $isCurrent);
            $waiting = $open ? seconds_since($m['sent_at']) : 0;
            $stuck = $open && $waiting > $alertHours * 3600;
            $state = $recalled ? 'is-recalled' : ($open ? 'is-open' : 'is-received'); ?>
            <li class="timeline-item <?= $state ?>">
                <span class="timeline-icon" aria-hidden="true"><i class="bi bi-<?= $icon ?>"></i></span>
                <div class="timeline-body">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div class="fw-semibold">
                            <?= e($actionLabel) ?>:
                            <?= e($m['from_department_name']) ?>
                            <i class="bi bi-arrow-right" aria-hidden="true"></i><span class="visually-hidden">to</span>
                            <?= e($m['to_department_name']) ?>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php if ($overdue): ?><span class="status-pill status-overdue"><i class="bi bi-alarm" aria-hidden="true"></i> Overdue</span><?php endif; ?>
                            <?php if ($open): ?>
                                <span class="status-pill status-in_transit">Awaiting receipt</span>
                            <?php elseif ($recalled): ?>
                                <span class="status-pill status-draft">Recalled</span>
                            <?php else: ?>
                                <span class="status-pill status-received">Received</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="timeline-note">“<?= e($m['note']) ?>”</div>

                    <dl class="timeline-meta">
                        <dt>Sent by</dt>
                        <dd><?= e($m['from_user_name']) ?>, <?= time_ago($m['sent_at']) ?>
                            <?php if ($m['to_user_name']): ?><span class="text-body-secondary">· for <?= e($m['to_user_name']) ?></span><?php endif; ?>
                        </dd>
                        <?php if ($m['due_date']): ?>
                            <dt>Due</dt>
                            <dd class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>"><?= e(format_datetime($m['due_date'], 'D j M Y')) ?></dd>
                        <?php endif; ?>

                        <?php if ($open): ?>
                            <dt>Received</dt>
                            <dd class="<?= $stuck ? 'text-warning-emphasis fw-semibold' : 'text-warning-emphasis' ?>">
                                Awaiting receipt for <?= e(human_duration($waiting)) ?>
                            </dd>
                        <?php elseif ($recalled): ?>
                            <dt>Recalled by</dt>
                            <dd><?= e($m['recalled_by_name']) ?>, <?= time_ago($m['recalled_at']) ?></dd>
                            <dt>Reason</dt>
                            <dd>“<?= e($m['recall_reason']) ?>”</dd>
                        <?php else:
                            [$methodIcon, $methodLabel] = $methodMeta[$m['receive_method']] ?? ['check', $m['receive_method']]; ?>
                            <dt>Received by</dt>
                            <dd>
                                <?= e($m['received_by_name']) ?>, <?= time_ago($m['received_at']) ?>
                                <i class="bi bi-<?= $methodIcon ?> text-body-secondary" title="Received by <?= e($methodLabel) ?>" aria-label="Received by <?= e($methodLabel) ?>"></i>
                            </dd>
                            <dt>Time taken</dt>
                            <dd><?= e(human_duration(strtotime((string) $m['received_at']) - strtotime((string) $m['sent_at']))) ?></dd>
                            <?php if ($m['receipt_note']): ?>
                                <dt>Receipt note</dt>
                                <dd>“<?= e($m['receipt_note']) ?>”</dd>
                            <?php endif; ?>
                        <?php endif; ?>
                    </dl>
                </div>
            </li>
        <?php endforeach; ?>
        <li class="timeline-item is-origin">
            <span class="timeline-icon" aria-hidden="true"><i class="bi bi-file-earmark-plus"></i></span>
            <div class="timeline-body">
                <div class="fw-semibold">Registered at <?= e($document['origin_department_name']) ?></div>
                <div class="small text-body-secondary">By <?= e($document['created_by_name']) ?>, <?= time_ago($document['created_at']) ?></div>
            </div>
        </li>
    </ol>
<?php endif; ?>
