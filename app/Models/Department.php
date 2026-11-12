<?php

declare(strict_types=1);

namespace App\Models;

/**
 * University departments / offices. Deactivated (is_active = 0), never deleted.
 */
final class Department extends Model
{
    protected string $table = 'departments';

    /** @var list<string> */
    protected array $fillable = ['name', 'code', 'parent_id', 'head_user_id', 'location', 'email', 'is_active'];

    /**
     * Find a department by its short code (e.g. "REG").
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        return $this->first(['code' => strtoupper(trim($code))]);
    }

    /**
     * Active departments ordered by name.
     *
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        return $this->where(['is_active' => 1], 'name ASC');
    }

    /**
     * Admin list: search by name/code, filter by status, with parent, HOD and user count.
     *
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function search(string $q, string $status, int $page, int $perPage = 25): array
    {
        $where = [];
        $bindings = [];
        if ($q !== '') {
            $where[] = '(d.name LIKE ? OR d.code LIKE ?)';
            array_push($bindings, self::likeTerm($q), self::likeTerm($q));
        }
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 'd.is_active = ?';
            $bindings[] = $status === 'active' ? 1 : 0;
        }

        $fromWhere = 'FROM departments d
            LEFT JOIN departments p ON p.id = d.parent_id
            LEFT JOIN users h ON h.id = d.head_user_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        return $this->paginateSql(
            'd.*, p.name AS parent_name, h.full_name AS head_name,
             (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.is_active = 1) AS active_users',
            $fromWhere,
            $bindings,
            'd.name ASC',
            $page,
            $perPage
        );
    }

    /**
     * Number of active users in the department.
     */
    public function activeUserCount(int $id): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE department_id = ? AND is_active = 1', [$id]);
    }

    /**
     * Documents the department currently holds or that are on their way to it.
     */
    public function openDocumentCount(int $id): int
    {
        return (int) $this->db->fetchValue(
            "SELECT
                (SELECT COUNT(*) FROM documents WHERE current_department_id = ? AND status IN ('draft', 'received'))
              + (SELECT COUNT(*) FROM document_movements WHERE to_department_id = ? AND status = 'in_transit')",
            [$id, $id]
        );
    }
}
