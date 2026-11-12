<?php

declare(strict_types=1);

namespace App\Models;

/**
 * One transfer of a document between departments. Rows are permanent history: never deleted.
 */
final class DocumentMovement extends Model
{
    protected string $table = 'document_movements';

    /** @var list<string> */
    protected array $fillable = [
        'document_id', 'action', 'from_department_id', 'from_user_id', 'to_department_id', 'to_user_id',
        'note', 'due_date', 'status', 'sent_at', 'received_by', 'received_at', 'receive_method',
        'receipt_note', 'recalled_by', 'recalled_at', 'recall_reason', 'last_alert_at',
    ];

    /**
     * IDs of every department a document has been sent from or to (its route).
     *
     * @return list<int>
     */
    public function routeDepartmentIds(int $documentId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT from_department_id AS id FROM document_movements WHERE document_id = ?
             UNION
             SELECT to_department_id FROM document_movements WHERE document_id = ?',
            [$documentId, $documentId]
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * The movement still waiting for receipt, if any (at most one per document).
     *
     * @return array<string, mixed>|null
     */
    public function openFor(int $documentId): ?array
    {
        return $this->first(['document_id' => $documentId, 'status' => 'in_transit']);
    }

    /**
     * The open movement, locked until the transaction ends. Lock the document row first.
     *
     * @return array<string, mixed>|null
     */
    public function lockOpenFor(int $documentId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM document_movements WHERE document_id = ? AND status = 'in_transit' LIMIT 1 FOR UPDATE",
            [$documentId]
        );
    }

    /**
     * All movements of a document with department and people names, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function historyFor(int $documentId): array
    {
        return $this->db->fetchAll(
            'SELECT m.*,
                    fd.name AS from_department_name, fd.code AS from_department_code,
                    td.name AS to_department_name, td.code AS to_department_code,
                    fu.full_name AS from_user_name, tu.full_name AS to_user_name,
                    ru.full_name AS received_by_name, cu.full_name AS recalled_by_name
             FROM document_movements m
             JOIN departments fd ON fd.id = m.from_department_id
             JOIN departments td ON td.id = m.to_department_id
             JOIN users fu ON fu.id = m.from_user_id
             LEFT JOIN users tu ON tu.id = m.to_user_id
             LEFT JOIN users ru ON ru.id = m.received_by
             LEFT JOIN users cu ON cu.id = m.recalled_by
             WHERE m.document_id = ?
             ORDER BY m.sent_at DESC, m.id DESC',
            [$documentId]
        );
    }

    /**
     * Movements waiting for a department to receive them, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function incomingFor(int $departmentId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT m.id AS movement_id, m.document_id, m.action, m.note, m.sent_at, m.due_date, m.to_user_id,
                    d.tracking_no, d.title, d.confidentiality, d.priority,
                    fd.name AS from_department_name, fu.full_name AS from_user_name
             FROM document_movements m
             JOIN documents d ON d.id = m.document_id
             JOIN departments fd ON fd.id = m.from_department_id
             JOIN users fu ON fu.id = m.from_user_id
             WHERE m.to_department_id = ? AND m.status = 'in_transit'
             ORDER BY m.sent_at ASC
             LIMIT " . max(1, min(500, $limit)),
            [$departmentId]
        );
    }

    /**
     * Open movements not received within $hours and not alerted within the last
     * $repeatHours (FR-10 step 1).
     *
     * @return list<array<string, mixed>>
     */
    public function unreceivedForAlert(int $hours, int $repeatHours): array
    {
        return $this->db->fetchAll(
            'SELECT m.id, m.document_id, m.from_user_id, m.from_department_id, m.to_department_id, m.to_user_id, m.sent_at, m.due_date,
                    TIMESTAMPDIFF(MINUTE, m.sent_at, NOW()) AS minutes_waiting,
                    d.tracking_no, d.title, d.confidentiality, d.priority,
                    fd.name AS from_department_name, td.name AS to_department_name, fu.full_name AS from_user_name
             FROM document_movements m
             JOIN documents d ON d.id = m.document_id
             JOIN departments fd ON fd.id = m.from_department_id
             JOIN departments td ON td.id = m.to_department_id
             JOIN users fu ON fu.id = m.from_user_id
             WHERE m.status = \'in_transit\' AND d.status = \'in_transit\'
               AND m.sent_at < (NOW() - INTERVAL ' . max(1, $hours) . ' HOUR)
               AND (m.last_alert_at IS NULL OR m.last_alert_at < (NOW() - INTERVAL ' . max(1, $repeatHours) . ' HOUR))
             ORDER BY m.sent_at',
        );
    }

    /**
     * Received documents past the due date of the movement that brought them to their
     * holder, not alerted within the last $repeatHours (FR-10 step 2).
     *
     * @return list<array<string, mixed>>
     */
    public function overdueForAlert(int $repeatHours): array
    {
        return $this->db->fetchAll(
            'SELECT m.id, m.document_id, m.due_date, m.received_at, m.to_department_id,
                    DATEDIFF(CURDATE(), m.due_date) AS days_overdue,
                    d.tracking_no, d.title, d.confidentiality, d.priority, d.current_department_id,
                    cd.name AS department_name, hu.full_name AS holder_name
             FROM documents d
             JOIN document_movements m ON m.id = d.current_movement_id
             JOIN departments cd ON cd.id = d.current_department_id
             LEFT JOIN users hu ON hu.id = d.current_holder_id
             WHERE d.status = \'received\' AND m.due_date IS NOT NULL AND m.due_date < CURDATE()
               AND (m.last_alert_at IS NULL OR m.last_alert_at < (NOW() - INTERVAL ' . max(1, $repeatHours) . ' HOUR))
             ORDER BY m.due_date',
        );
    }

    /**
     * Record that an alert was sent for a movement (prevents repeats within the window).
     */
    public function markAlerted(int $movementId): void
    {
        $this->db->execute('UPDATE document_movements SET last_alert_at = NOW() WHERE id = ?', [$movementId]);
    }

    /**
     * Latest movement activity involving a department (sent from or to it), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recentForDepartment(int $departmentId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $activity = 'GREATEST(m.sent_at, COALESCE(m.received_at, m.sent_at), COALESCE(m.recalled_at, m.sent_at))';

        // Pick the IDs from the movement table alone (two index lookups), then load names for those rows.
        $ids = array_map('intval', array_column($this->db->fetchAll(
            "SELECT id FROM (
                 SELECT m.id, $activity AS activity_at FROM document_movements m WHERE m.from_department_id = ?
                 UNION
                 SELECT m.id, $activity AS activity_at FROM document_movements m WHERE m.to_department_id = ?
             ) x ORDER BY activity_at DESC, id DESC LIMIT $limit",
            [$departmentId, $departmentId]
        ), 'id'));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->fetchAll(
            "SELECT m.id, m.document_id, m.action, m.status, m.sent_at, m.received_at, m.recalled_at,
                    $activity AS activity_at,
                    d.tracking_no, d.title, d.confidentiality, d.priority, d.status AS document_status,
                    fd.code AS from_code, td.code AS to_code, m.from_department_id, m.to_department_id
             FROM document_movements m
             JOIN documents d ON d.id = m.document_id
             JOIN departments fd ON fd.id = m.from_department_id
             JOIN departments td ON td.id = m.to_department_id
             WHERE m.id IN ($placeholders)
             ORDER BY activity_at DESC, m.id DESC",
            $ids
        );
    }

    /**
     * Average time a department took to process documents over the last $days days:
     * from receiving a document to sending it on (next non-recalled movement out) or
     * completing it, counting only documents whose processing ended in the period.
     *
     * @return array{seconds: ?int, count: int}
     */
    public function averageProcessing(int $departmentId, int $days = 30): array
    {
        $row = $this->db->fetch(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, x.received_at, x.ended_at)) AS avg_seconds, COUNT(*) AS n
             FROM (
                 SELECT m.received_at,
                        COALESCE(
                            (SELECT MIN(n.sent_at) FROM document_movements n
                             WHERE n.document_id = m.document_id AND n.from_department_id = m.to_department_id
                               AND n.status <> 'recalled' AND n.sent_at >= m.received_at),
                            (SELECT d.completed_at FROM documents d
                             WHERE d.id = m.document_id AND d.status = 'completed'
                               AND d.current_department_id = m.to_department_id AND d.completed_at >= m.received_at)
                        ) AS ended_at
                 FROM document_movements m
                 WHERE m.to_department_id = ? AND m.status = 'received'
             ) x
             WHERE x.ended_at IS NOT NULL AND x.ended_at >= (NOW() - INTERVAL " . max(1, $days) . ' DAY)',
            [$departmentId]
        );

        return [
            'seconds' => $row !== null && $row['avg_seconds'] !== null ? (int) round((float) $row['avg_seconds']) : null,
            'count' => (int) ($row['n'] ?? 0),
        ];
    }

    /**
     * Number of movements waiting for a department to receive them.
     */
    public function countIncoming(int $departmentId): int
    {
        return $this->count(['to_department_id' => $departmentId, 'status' => 'in_transit']);
    }

    /**
     * Movements of a document in chronological order (for the progress strip).
     *
     * @return list<array<string, mixed>>
     */
    public function chronologicalFor(int $documentId): array
    {
        return array_reverse($this->historyFor($documentId));
    }

    /**
     * The most recent movement that was received (how the document reached its holder).
     *
     * @return array<string, mixed>|null
     */
    public function lastReceivedFor(int $documentId): ?array
    {
        return $this->db->fetch(
            'SELECT m.* FROM documents d JOIN document_movements m ON m.id = d.current_movement_id WHERE d.id = ?',
            [$documentId]
        );
    }
}
