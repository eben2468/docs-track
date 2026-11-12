<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentMovement;
use Core\Database;
use Core\Logger;

/**
 * Overdue and stuck detection (FR-10), run by cron/check_alerts.php every 30 minutes.
 *
 * 1. Unreceived: a movement in transit longer than unreceived_alert_hours alerts the
 *    destination department's staff and the sender. From 2× the threshold the
 *    destination's HOD(s) are alerted too (escalation).
 * 2. Overdue: a received document past its due date alerts everyone in the holder
 *    department, HOD included.
 * 3. No alert for the same movement is repeated within alert_repeat_hours
 *    (document_movements.last_alert_at).
 */
final class AlertService
{
    private const LOCK_NAME = 'vvu_dts_check_alerts';

    private readonly Database $db;

    public function __construct(
        private readonly DocumentMovement $movements = new DocumentMovement(),
        private readonly NotificationService $notifications = new NotificationService(),
        ?Database $db = null,
    ) {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Run both checks once. Returns what was done; "locked" means another run was in progress.
     *
     * @return array{locked: bool, unreceived: int, escalated: int, overdue: int, notified: int}
     */
    public function run(): array
    {
        $summary = ['locked' => false, 'unreceived' => 0, 'escalated' => 0, 'overdue' => 0, 'notified' => 0];

        if ((int) $this->db->fetchValue('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME]) !== 1) {
            return ['locked' => true] + $summary;
        }

        try {
            $hours = max(1, (int) setting('unreceived_alert_hours'));
            $repeat = max(1, (int) setting('alert_repeat_hours'));

            foreach ($this->movements->unreceivedForAlert($hours, $repeat) as $m) {
                $this->db->transaction(function () use ($m, $hours, &$summary): void {
                    $escalate = (int) $m['minutes_waiting'] >= 2 * $hours * 60;
                    $summary['notified'] += $this->alertUnreceived($m, $escalate);
                    $this->movements->markAlerted((int) $m['id']);
                    $summary['unreceived']++;
                    $summary['escalated'] += $escalate ? 1 : 0;
                });
            }

            foreach ($this->movements->overdueForAlert($repeat) as $m) {
                $this->db->transaction(function () use ($m, &$summary): void {
                    $summary['notified'] += $this->alertOverdue($m);
                    $this->movements->markAlerted((int) $m['id']);
                    $summary['overdue']++;
                });
            }
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }

        Logger::info(sprintf(
            'check_alerts: %d unreceived (%d escalated to HOD), %d overdue, %d notification(s).',
            $summary['unreceived'],
            $summary['escalated'],
            $summary['overdue'],
            $summary['notified']
        ));

        return $summary;
    }

    /**
     * @param array<string, mixed> $m Row from DocumentMovement::unreceivedForAlert()
     */
    private function alertUnreceived(array $m, bool $escalate): int
    {
        $label = $this->label($m);
        $waited = human_duration((int) $m['minutes_waiting'] * 60);
        $toDept = (int) $m['to_department_id'];
        [$staff, $hods] = $this->departmentUsers($toDept);

        // Staff of the destination (everyone, if the department has only HODs), plus HODs once escalated.
        $recipients = $staff !== [] ? $staff : $hods;
        if ($escalate) {
            $recipients = array_merge($recipients, $hods);
        }

        $count = $this->notifications->notify(
            $recipients,
            (int) $m['document_id'],
            'unreceived_alert',
            sprintf(
                '%s was sent to %s by %s (%s) %s ago and has not been received.%s Please receive it if you have it, or contact the sender.',
                $label,
                $m['to_department_name'],
                $m['from_user_name'],
                $m['from_department_name'],
                $waited,
                $escalate ? ' This alert has been escalated to the head of department.' : ''
            ),
            null,
            ($escalate ? 'still not received by ' : 'not yet received by ') . $m['to_department_name']
        );

        $count += $this->notifications->notify(
            (int) $m['from_user_id'],
            (int) $m['document_id'],
            'unreceived_alert',
            sprintf('%s, which you sent to %s %s ago, has not been received yet. You may want to follow up or recall it.', $label, $m['to_department_name'], $waited),
            null,
            'not yet received by ' . $m['to_department_name']
        );

        return $count;
    }

    /**
     * @param array<string, mixed> $m Row from DocumentMovement::overdueForAlert()
     */
    private function alertOverdue(array $m): int
    {
        $days = (int) $m['days_overdue'];

        return $this->notifications->notifyDepartment(
            (int) $m['current_department_id'],
            (int) $m['document_id'],
            'overdue_alert',
            sprintf(
                '%s is overdue at %s: it was due on %s (%d day%s ago)%s. Please act on it, forward it or mark it completed.',
                $this->label($m),
                $m['department_name'],
                format_datetime((string) $m['due_date'], 'j M Y'),
                $days,
                $days === 1 ? '' : 's',
                $m['holder_name'] ? ' and is held by ' . $m['holder_name'] : ''
            ),
            null,
            'is overdue at ' . $m['department_name']
        );
    }

    /**
     * Active users of a department split into [staff IDs, HOD IDs]. HODs are users with the
     * "hod" role plus the department's appointed head.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function departmentUsers(int $departmentId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT u.id, (u.role = \'hod\' OR u.id = d.head_user_id) AS is_hod
             FROM users u JOIN departments d ON d.id = u.department_id
             WHERE u.department_id = ? AND u.is_active = 1',
            [$departmentId]
        );

        $staff = [];
        $hods = [];
        foreach ($rows as $r) {
            if ((int) $r['is_hod'] === 1) {
                $hods[] = (int) $r['id'];
            } else {
                $staff[] = (int) $r['id'];
            }
        }

        return [$staff, $hods];
    }

    /**
     * @param array<string, mixed> $m
     */
    private function label(array $m): string
    {
        return $m['confidentiality'] === 'confidential'
            ? (string) $m['tracking_no']
            : sprintf('%s "%s"', $m['tracking_no'], mb_strimwidth((string) $m['title'], 0, 80, '…'));
    }
}
