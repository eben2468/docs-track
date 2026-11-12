<?php

declare(strict_types=1);

namespace App\Models;

use LogicException;

/**
 * Append-only audit trail (FR-13). Rows can be inserted and read, never changed or deleted.
 */
final class AuditLog extends Model
{
    protected string $table = 'audit_logs';

    /** @var list<string> */
    protected array $fillable = ['user_id', 'document_id', 'action', 'details', 'ip_address', 'user_agent'];

    /**
     * Insert a log row; an array in "details" is stored as JSON.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        if (isset($data['details']) && is_array($data['details'])) {
            $data['details'] = json_encode($data['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return parent::insert($data);
    }

    /**
     * Audit logs are immutable.
     *
     * @param array<string, mixed> $data
     */
    public function update(int|string $id, array $data): int
    {
        throw new LogicException('Audit log entries cannot be modified.');
    }

    /**
     * Filtered, paginated log for the admin viewer (FR-13).
     *
     * @param array<string, string> $filters action, user (name/email/staff ID), document (tracking no.), ip, from, to (Y-m-d)
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function search(array $filters, int $page, int $perPage = 50): array
    {
        $where = [];
        $bindings = [];

        if (($filters['action'] ?? '') !== '') {
            if (str_ends_with($filters['action'], '.*')) {
                $where[] = 'a.action LIKE ?';
                $bindings[] = addcslashes(substr($filters['action'], 0, -1), '%_\\') . '%';
            } else {
                $where[] = 'a.action = ?';
                $bindings[] = $filters['action'];
            }
        }
        if (($filters['user'] ?? '') !== '') {
            $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.staff_id LIKE ?)';
            array_push($bindings, self::likeTerm($filters['user']), self::likeTerm($filters['user']), self::likeTerm($filters['user']));
        }
        if (($filters['document'] ?? '') !== '') {
            $where[] = 'd.tracking_no LIKE ?';
            $bindings[] = self::likeTerm($filters['document']);
        }
        if (($filters['ip'] ?? '') !== '') {
            $where[] = 'a.ip_address LIKE ?';
            $bindings[] = addcslashes(trim($filters['ip']), '%_\\') . '%';
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($filters[$key] ?? ''));
            if ($date !== false && $date->format('Y-m-d') === ($filters[$key] ?? '')) {
                $where[] = "a.created_at $op ?";
                $bindings[] = ($key === 'to' ? $date->modify('+1 day') : $date)->format('Y-m-d H:i:s');
            }
        }

        return $this->paginateSql(
            'a.id, a.action, a.details, a.ip_address, a.user_agent, a.created_at, a.user_id, a.document_id,
             u.full_name AS user_name, u.staff_id, d.tracking_no',
            'FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN documents d ON d.id = a.document_id'
                . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)),
            $bindings,
            'a.id DESC',
            $page,
            $perPage
        );
    }

    /**
     * Every action name that has been logged, alphabetically (for the filter list).
     *
     * @return list<string>
     */
    public function distinctActions(): array
    {
        return array_column($this->db->fetchAll('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
    }

    /**
     * Most recent entries with user and document names (admin activity feed).
     *
     * @param list<string>|null $actionPrefixes Only actions starting with these, e.g. ['document.', 'user.']
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 15, ?array $actionPrefixes = null): array
    {
        $where = '';
        $bindings = [];
        if ($actionPrefixes !== null && $actionPrefixes !== []) {
            $where = 'WHERE (' . implode(' OR ', array_fill(0, count($actionPrefixes), 'a.action LIKE ?')) . ')';
            $bindings = array_map(static fn (string $p): string => addcslashes($p, '%_\\') . '%', $actionPrefixes);
        }

        return $this->db->fetchAll(
            "SELECT a.id, a.action, a.details, a.created_at, a.document_id, a.user_id,
                    u.full_name AS user_name, d.tracking_no, d.title, d.confidentiality
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN documents d ON d.id = a.document_id
             $where
             ORDER BY a.id DESC
             LIMIT " . max(1, min(100, $limit)),
            $bindings
        );
    }
}
