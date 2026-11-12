<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Document types (Memo, Letter, ...) with default SLA in working days.
 */
final class DocumentType extends Model
{
    protected string $table = 'document_types';

    /** @var list<string> */
    protected array $fillable = ['name', 'sla_days', 'is_active'];
}
