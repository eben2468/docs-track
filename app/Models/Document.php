<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Registered documents. Status changes go through DocumentService/MovementService only.
 */
final class Document extends Model
{
    protected string $table = 'documents';

    /** @var list<string> */
    protected array $fillable = [
        'tracking_no', 'title', 'document_type_id', 'reference_no', 'description', 'priority',
        'confidentiality', 'format', 'pages', 'origin_department_id', 'created_by',
        'current_department_id', 'current_holder_id', 'status', 'completed_at', 'completed_by',
    ];

    /**
     * Find a document by tracking number (e.g. "VVU-REG-2026-00042").
     *
     * @return array<string, mixed>|null
     */
    public function findByTrackingNo(string $trackingNo): ?array
    {
        return $this->first(['tracking_no' => strtoupper(trim($trackingNo))]);
    }
}
