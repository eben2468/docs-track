<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\Notification;
use Core\Database;

/**
 * Notifications (FR-9): every event creates an in-app notification and queues an email
 * for each active recipient.
 *
 * Types: incoming, received, returned, recalled, completed, unreceived_alert, overdue_alert.
 * Targeting (decided by callers): the specific recipient if one was chosen, otherwise all
 * active users of the department. The acting user is never notified of their own action.
 */
final class NotificationService
{
    /** Default subject wording per type, used when the caller gives no headline. */
    private const HEADLINES = [
        'incoming' => 'sent to your department',
        'received' => 'received',
        'returned' => 'returned',
        'recalled' => 'recalled',
        'completed' => 'completed',
        'unreceived_alert' => 'not yet received',
        'overdue_alert' => 'is overdue',
    ];

    private readonly Database $db;

    public function __construct(
        private readonly Notification $notifications = new Notification(),
        private readonly Document $documents = new Document(),
        private readonly MailService $mail = new MailService(),
        ?Database $db = null,
    ) {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Notify users in-app and by email. Inactive users, duplicates and $exceptUserId are
     * skipped. $headline completes the email subject, e.g. "sent to Bursary" gives
     * "[URGENT] VVU-REG-2026-00042 sent to Bursary". Returns the number of users notified.
     *
     * @param list<int>|int $userIds
     */
    public function notify(
        array|int $userIds,
        ?int $documentId,
        string $type,
        string $message,
        ?int $exceptUserId = null,
        string $headline = '',
    ): int {
        $ids = array_values(array_unique(array_map('intval', (array) $userIds)));
        if ($exceptUserId !== null) {
            $ids = array_values(array_diff($ids, [$exceptUserId]));
        }
        $recipients = $this->activeUsers($ids);
        if ($recipients === []) {
            return 0;
        }

        $document = $documentId !== null ? $this->documents->find($documentId) : null;
        $subject = self::subject($document, $headline !== '' ? $headline : (self::HEADLINES[$type] ?? $type), $message);
        $html = MailService::render('emails/notification', $subject, [
            'message' => $message,
            'document' => $document,
            'type' => $type,
            'link' => $document !== null ? url('/documents/' . $document['id']) : url('/notifications'),
        ]);

        foreach ($recipients as $user) {
            $this->notifications->insert([
                'user_id' => (int) $user['id'],
                'document_id' => $documentId,
                'type' => $type,
                'message' => mb_substr($message, 0, 500),
            ]);
            if ((string) $user['email'] !== '') {
                $this->mail->queue((string) $user['email'], $subject, $html);
            }
        }

        return count($recipients);
    }

    /**
     * Notify every active user of a department.
     */
    public function notifyDepartment(
        int $departmentId,
        ?int $documentId,
        string $type,
        string $message,
        ?int $exceptUserId = null,
        string $headline = '',
    ): int {
        $ids = array_map('intval', array_column(
            $this->db->fetchAll('SELECT id FROM users WHERE department_id = ? AND is_active = 1', [$departmentId]),
            'id'
        ));

        return $this->notify($ids, $documentId, $type, $message, $exceptUserId, $headline);
    }

    /**
     * Email subject: "[URGENT] VVU-REG-2026-00042 sent to Bursary" (priority prefix only for urgent).
     *
     * @param array<string, mixed>|null $document
     */
    public static function subject(?array $document, string $headline, string $fallback): string
    {
        if ($document === null) {
            return mb_strimwidth($fallback, 0, 200, '…');
        }

        return trim(($document['priority'] === 'urgent' ? '[URGENT] ' : '') . $document['tracking_no'] . ' ' . $headline);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function activeUsers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->fetchAll(
            "SELECT id, email, full_name FROM users WHERE is_active = 1 AND id IN ($placeholders) ORDER BY id",
            $ids
        );
    }
}
