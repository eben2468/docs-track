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
