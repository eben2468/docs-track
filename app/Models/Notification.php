<?php

declare(strict_types=1);

namespace App\Models;

/**
 * In-app notifications. Every query is scoped to the owning user.
 */
final class Notification extends Model
{
    protected string $table = 'notifications';

    /** @var list<string> */
    protected array $fillable = ['user_id', 'document_id', 'type', 'message', 'is_read'];

    public function unreadCount(int $userId): int
    {
        return $this->count(['user_id' => $userId, 'is_read' => 0]);
    }

    /**
     * The user's most recent notifications with the document's tracking number.
     *
     * @return list<array<string, mixed>>
     */
    public function latestFor(int $userId, int $limit = 5): array
    {
        return $this->db->fetchAll(
            'SELECT n.id, n.type, n.message, n.is_read, n.created_at, n.document_id, d.tracking_no
             FROM notifications n LEFT JOIN documents d ON d.id = n.document_id
             WHERE n.user_id = ?
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT ' . max(1, min(50, $limit)),
            [$userId]
        );
    }

    /**
     * One page of the user's notifications, optionally unread only.
     *
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function pageFor(int $userId, bool $unreadOnly, int $page, int $perPage = 25): array
    {
        return $this->paginateSql(
            'n.id, n.type, n.message, n.is_read, n.created_at, n.document_id, d.tracking_no',
            'FROM notifications n LEFT JOIN documents d ON d.id = n.document_id WHERE n.user_id = ?' . ($unreadOnly ? ' AND n.is_read = 0' : ''),
            [$userId],
            'n.created_at DESC, n.id DESC',
            $page,
            $perPage
        );
    }

    /**
     * A notification only if it belongs to the user.
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        return $this->first(['id' => $id, 'user_id' => $userId]);
    }

    /**
     * Mark one of the user's notifications as read. Returns affected rows.
     */
    public function markRead(int $id, int $userId): int
    {
        return $this->db->execute('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0', [$id, $userId]);
    }

    /**
     * Mark all of the user's notifications as read. Returns affected rows.
     */
    public function markAllRead(int $userId): int
    {
        return $this->db->execute('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$userId]);
    }
}
