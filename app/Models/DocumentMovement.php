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
