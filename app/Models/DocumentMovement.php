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
}
