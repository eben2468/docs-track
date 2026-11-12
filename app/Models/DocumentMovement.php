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
            "SELECT * FROM document_movements WHERE document_id = ? AND status = 'received'
             ORDER BY received_at DESC, id DESC LIMIT 1",
            [$documentId]
        );
    }
}
