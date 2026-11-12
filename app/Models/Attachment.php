<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Files attached to a document. Never deleted; an admin can only hide them (is_hidden).
 */
final class Attachment extends Model
{
    protected string $table = 'attachments';

    /** @var list<string> */
    protected array $fillable = [
        'document_id', 'original_name', 'stored_path', 'mime_type', 'size_bytes', 'uploaded_by', 'is_hidden',
    ];

    /**
     * A document's attachments with uploader names, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forDocument(int $documentId, bool $includeHidden): array
    {
        return $this->db->fetchAll(
            'SELECT a.*, u.full_name AS uploaded_by_name
             FROM attachments a JOIN users u ON u.id = a.uploaded_by
             WHERE a.document_id = ?' . ($includeHidden ? '' : ' AND a.is_hidden = 0') . '
             ORDER BY a.created_at, a.id',
            [$documentId]
        );
    }

    /**
     * Number of visible attachments on a document.
     */
    public function visibleCount(int $documentId): int
    {
        return $this->count(['document_id' => $documentId, 'is_hidden' => 0]);
    }
}
