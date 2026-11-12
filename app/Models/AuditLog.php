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
}
