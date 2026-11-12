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

    /**
     * A document with the names needed for display (type, departments, people).
     *
     * @return array<string, mixed>|null
     */
    public function findDetailed(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT d.*,
                    t.name AS type_name, t.sla_days AS type_sla_days,
                    od.name AS origin_department_name, od.code AS origin_department_code,
                    cd.name AS current_department_name, cd.code AS current_department_code,
                    cu.full_name AS created_by_name,
                    hu.full_name AS current_holder_name,
                    pu.full_name AS completed_by_name
             FROM documents d
             JOIN document_types t ON t.id = d.document_type_id
             JOIN departments od ON od.id = d.origin_department_id
             JOIN departments cd ON cd.id = d.current_department_id
             JOIN users cu ON cu.id = d.created_by
             LEFT JOIN users hu ON hu.id = d.current_holder_id
             LEFT JOIN users pu ON pu.id = d.completed_by
             WHERE d.id = ?',
            [$id]
        );
    }

    /**
     * Lock a document row until the current transaction ends (SELECT ... FOR UPDATE).
     * Every state change must call this first.
     *
     * @return array<string, mixed>|null
     */
    public function lockForUpdate(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM documents WHERE id = ? FOR UPDATE', [$id]);
    }
}
