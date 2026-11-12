<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;
use Core\Database;

/**
 * In-app notifications (FR-9). Email delivery of the same events is added with the mail queue.
 *
 * Types: incoming, received, returned, recalled, completed, unreceived_alert, overdue_alert.
 */
final class NotificationService
{
    private readonly Database $db;

    public function __construct(private readonly Notification $notifications = new Notification(), ?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Notify users. Duplicates and the acting user ($exceptUserId) are skipped, so nobody
     * is told about their own action. Returns the number of notifications created.
     *
     * @param list<int>|int $userIds
     */
    public function notify(array|int $userIds, ?int $documentId, string $type, string $message, ?int $exceptUserId = null): int
    {
        $ids = array_values(array_unique(array_map('intval', (array) $userIds)));
        if ($exceptUserId !== null) {
            $ids = array_values(array_diff($ids, [$exceptUserId]));
        }

        foreach ($ids as $userId) {
            $this->notifications->insert([
                'user_id' => $userId,
                'document_id' => $documentId,
                'type' => $type,
                'message' => mb_substr($message, 0, 500),
            ]);
        }

        return count($ids);
    }

    /**
     * Notify every active user of a department.
     */
    public function notifyDepartment(int $departmentId, ?int $documentId, string $type, string $message, ?int $exceptUserId = null): int
    {
        return $this->notify($this->activeUserIds($departmentId), $documentId, $type, $message, $exceptUserId);
    }

    /**
     * @return list<int>
     */
    private function activeUserIds(int $departmentId): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll('SELECT id FROM users WHERE department_id = ? AND is_active = 1', [$departmentId]),
            'id'
        ));
    }
}
