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
}
