<?php
/**
 * Horizontal strip of the departments a document has visited, in order (FR-8).
 * Recalled sends are skipped; an unreceived destination is shown dashed.
 *
 * @var array<string, mixed> $document
 * @var list<array<string, mixed>> $history Movements, newest first
 */
$steps = [['code' => $document['origin_department_code'], 'name' => $document['origin_department_name'], 'state' => 'done']];
foreach (array_reverse($history) as $m) {
    if ($m['status'] === 'received') {
        $steps[] = ['code' => $m['to_department_code'], 'name' => $m['to_department_name'], 'state' => 'done'];
    } elseif ($m['status'] === 'in_transit') {
        $steps[] = ['code' => $m['to_department_code'], 'name' => $m['to_department_name'], 'state' => 'pending'];
    }
}
$lastDone = 0;
foreach ($steps as $i => $s) {
    if ($s['state'] === 'done') {
        $lastDone = $i;
    }
}
$terminal = in_array($document['status'], ['completed', 'cancelled'], true);
?>
<ol class="progress-strip" aria-label="Departments visited">
    <?php foreach ($steps as $i => $s):
        $class = $s['state'] === 'pending' ? 'is-pending' : ($i === $lastDone ? ($terminal ? 'is-final' : 'is-current') : 'is-done'); ?>
        <li class="progress-step <?= $class ?>" title="<?= e($s['name']) ?>">
            <span class="progress-dot" aria-hidden="true">
                <?php if ($class === 'is-pending'): ?><i class="bi bi-truck"></i>
                <?php elseif ($class === 'is-final'): ?><i class="bi bi-check-lg"></i>
                <?php elseif ($class === 'is-current'): ?><i class="bi bi-geo-alt-fill"></i>
                <?php else: ?><i class="bi bi-check"></i><?php endif; ?>
            </span>
            <span class="progress-label"><?= e($s['code']) ?></span>
            <span class="visually-hidden">
                <?= e($s['name']) ?>: <?= ['is-pending' => 'in transit, not yet received', 'is-current' => 'current location', 'is-final' => 'final location', 'is-done' => 'visited'][$class] ?>
            </span>
        </li>
    <?php endforeach; ?>
</ol>
