<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Core\Auth;

/**
 * Writes append-only audit_logs rows (FR-13). Never updates or deletes.
 */
final class AuditService
{
    /** Columns never written into audit details. */
    private const HIDDEN_FIELDS = ['password_hash', 'password', 'token_hash'];

    public function __construct(private readonly AuditLog $logs = new AuditLog())
    {
    }

    /**
     * Record an action. The user defaults to the logged-in user; IP and user agent
     * come from the current HTTP request (null on the command line).
     *
     * @param array<string, mixed> $details Extra context, stored as JSON
     */
    public function log(string $action, array $details = [], ?int $documentId = null, ?int $userId = null): void
    {
        $this->logs->insert([
            'user_id' => $userId ?? Auth::id(),
            'document_id' => $documentId,
            'action' => $action,
            'details' => $details === [] ? null : $details,
            'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    }

    /**
     * Fields whose values differ between two versions of a row, as field => [old, new].
     * Sensitive fields are skipped.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $new) {
            if (in_array($field, self::HIDDEN_FIELDS, true) || !array_key_exists($field, $before)) {
                continue;
            }
            $old = $before[$field];
            if ((string) ($old ?? "\0null") !== (string) ($new ?? "\0null")) {
                $changes[$field] = [$old, $new];
            }
        }

        return $changes;
    }
}
