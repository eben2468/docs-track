<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Staff accounts. Each user belongs to exactly one department. Deactivated, never deleted.
 */
final class User extends Model
{
    protected string $table = 'users';

    /** @var list<string> */
    protected array $fillable = [
        'staff_id', 'full_name', 'email', 'phone', 'password_hash', 'role', 'department_id',
        'is_active', 'failed_attempts', 'locked_until', 'last_login_at',
    ];

    /**
     * Find a user by email address (case-insensitive under utf8mb4_unicode_ci).
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->first(['email' => trim($email)]);
    }

    /**
     * Find a user by email address or staff ID (login form accepts either).
     *
     * @return array<string, mixed>|null
     */
    public function findByLogin(string $login): ?array
    {
        $login = trim($login);

        return $this->db->fetch('SELECT * FROM users WHERE email = ? OR staff_id = ? LIMIT 1', [$login, $login]);
    }

    /**
     * Admin list with department name; search by name, email or staff ID.
     *
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function search(string $q, ?int $departmentId, string $role, string $status, int $page, int $perPage = 25): array
    {
        $where = [];
        $bindings = [];
        if ($q !== '') {
            $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.staff_id LIKE ?)';
            array_push($bindings, self::likeTerm($q), self::likeTerm($q), self::likeTerm($q));
        }
        if ($departmentId !== null) {
            $where[] = 'u.department_id = ?';
            $bindings[] = $departmentId;
        }
        if (in_array($role, ['admin', 'hod', 'staff'], true)) {
            $where[] = 'u.role = ?';
            $bindings[] = $role;
        }
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 'u.is_active = ?';
            $bindings[] = $status === 'active' ? 1 : 0;
        } elseif ($status === 'locked') {
            $where[] = 'u.locked_until > NOW()';
        }

        $fromWhere = 'FROM users u JOIN departments d ON d.id = u.department_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        return $this->paginateSql(
            'u.id, u.staff_id, u.full_name, u.email, u.phone, u.role, u.is_active, u.locked_until,
             u.last_login_at, d.name AS department_name, d.code AS department_code',
            $fromWhere,
            $bindings,
            'u.full_name ASC',
            $page,
            $perPage
        );
    }

    /**
     * Users in a department (for HOD selection), active ones only by default.
     *
     * @return list<array<string, mixed>>
     */
    public function inDepartment(int $departmentId, bool $activeOnly = true): array
    {
        $conditions = ['department_id' => $departmentId];
        if ($activeOnly) {
            $conditions['is_active'] = 1;
        }

        return $this->where($conditions, 'full_name ASC');
    }

    public function activeAdminCount(): int
    {
        return $this->count(['role' => 'admin', 'is_active' => 1]);
    }

    /**
     * Atomically add one failed login; lock the account once the limit is reached.
     * Returns true when this attempt caused a lock.
     */
    public function registerFailedLogin(int $id, int $maxAttempts, int $lockMinutes): bool
    {
        $this->db->execute('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$id]);

        return $this->db->execute(
            'UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE), failed_attempts = 0
             WHERE id = ? AND failed_attempts >= ?',
            [$lockMinutes, $id, $maxAttempts]
        ) === 1;
    }
}
