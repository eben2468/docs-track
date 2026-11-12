<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Document types (Memo, Letter, ...) with default SLA in working days. Deactivated, never deleted.
 */
final class DocumentType extends Model
{
    protected string $table = 'document_types';

    /** @var list<string> */
    protected array $fillable = ['name', 'sla_days', 'is_active'];

    /**
     * Admin list with the number of documents of each type.
     *
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function search(string $q, string $status, int $page, int $perPage = 25): array
    {
        $where = [];
        $bindings = [];
        if ($q !== '') {
            $where[] = 't.name LIKE ?';
            $bindings[] = self::likeTerm($q);
        }
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 't.is_active = ?';
            $bindings[] = $status === 'active' ? 1 : 0;
        }

        return $this->paginateSql(
            't.*, (SELECT COUNT(*) FROM documents d WHERE d.document_type_id = t.id) AS document_count',
            'FROM document_types t' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)),
            $bindings,
            't.name ASC',
            $page,
            $perPage
        );
    }
}
