<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentScope;

/**
 * Figures for the dashboards (FR-11). Counts use DocumentScope, the same definitions as
 * the document lists, so every card matches the list it links to.
 */
final class DashboardService
{
    public function __construct(
        private readonly Document $documents = new Document(),
        private readonly DocumentMovement $movements = new DocumentMovement(),
        private readonly AuditLog $audit = new AuditLog(),
    ) {
    }

    /**
     * Staff/HOD dashboard for the user's department.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function department(array $user, int $alertHours): array
    {
        $dept = (int) $user['department_id'];
        $data = [
            'cards' => [
                'incoming' => $this->documents->countScoped([DocumentScope::incoming($dept)]),
                'with_us' => $this->documents->countScoped([DocumentScope::withUs($dept)]),
                'outgoing' => $this->documents->countScoped([DocumentScope::outgoing($dept)]),
                'overdue' => $this->documents->countScoped([DocumentScope::overdue($dept)]),
                'completed_month' => $this->documents->countScoped([DocumentScope::completedThisMonth($dept)]),
            ],
            'stuckIncoming' => $this->documents->countScoped([DocumentScope::stuck($alertHours, $dept)]),
            'awaiting' => $this->movements->incomingFor($dept, 10),
            'recent' => $this->movements->recentForDepartment($dept, 10),
            'isHod' => $user['role'] === 'hod',
        ];

        if ($data['isHod']) {
            $data['processing'] = $this->movements->averageProcessing($dept, 30);
            $data['overdueList'] = $this->documents->findScoped([DocumentScope::overdue($dept)], 'lr.due_date ASC, d.id ASC', 20);
        }

        return $data;
    }

    /**
     * University-wide admin dashboard.
     *
     * @return array<string, mixed>
     */
    public function admin(int $alertHours): array
    {
        $byStatus = $this->documents->countByStatus();

        return [
            'byStatus' => $byStatus,
            'total' => array_sum($byStatus),
            'overdueTotal' => $this->documents->countScoped([DocumentScope::overdue()]),
            'stuckTotal' => $this->documents->countScoped([DocumentScope::stuck($alertHours)]),
            'completedMonth' => $this->documents->countScoped([DocumentScope::completedThisMonth()]),
            'topOverdue' => $this->documents->topOverdueDepartments(5),
            'stuck' => $this->documents->findScoped([DocumentScope::stuck($alertHours)], 'om.sent_at ASC, d.id ASC', 20),
            'activity' => $this->audit->recent(15),
        ];
    }

    /**
     * Friendly description of an audit action for the activity feed.
     */
    public static function activityLabel(string $action): string
    {
        return [
            'login' => 'signed in',
            'logout' => 'signed out',
            'login.failed' => 'failed sign-in',
            'login.locked' => 'sign-in blocked (locked account)',
            'login.throttled' => 'sign-in throttled',
            'document.created' => 'registered',
            'document.sent' => 'sent',
            'document.forwarded' => 'forwarded',
            'document.returned' => 'returned',
            'document.received' => 'received',
            'document.recalled' => 'recalled',
            'document.completed' => 'completed',
            'document.cancelled' => 'cancelled',
            'document.viewed' => 'viewed (confidential)',
            'attachment.uploaded' => 'uploaded an attachment to',
            'attachment.downloaded' => 'downloaded an attachment from',
            'attachment.hidden' => 'hid an attachment on',
            'attachment.unhidden' => 'unhid an attachment on',
            'password.reset_requested' => 'requested a password reset',
            'password.reset' => 'reset their password',
            'password.changed' => 'changed their password',
            'profile.updated' => 'updated their profile',
            'settings.updated' => 'changed settings',
            'settings.test_email' => 'sent a test email',
        ][$action] ?? str_replace(['.', '_'], ' ', $action);
    }
}
