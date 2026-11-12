<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Password reset tokens (stored as SHA-256 hashes, single use).
 */
final class PasswordReset extends Model
{
    protected string $table = 'password_resets';

    /** @var list<string> */
    protected array $fillable = ['user_id', 'token_hash', 'expires_at', 'used_at'];

    /**
     * An unused, unexpired token for an active user, locked for update. Call inside a transaction.
     *
     * @return array<string, mixed>|null Reset row plus user email and full_name
     */
    public function findValidForUpdate(string $tokenHash): ?array
    {
        return $this->db->fetch(
            'SELECT r.*, u.email, u.full_name
             FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.is_active = 1
             LIMIT 1 FOR UPDATE',
            [$tokenHash]
        );
    }

    /**
     * Whether a token is currently usable (no lock; for showing the reset form).
     */
    public function isValid(string $tokenHash): bool
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.is_active = 1',
            [$tokenHash]
        ) > 0;
    }

    /**
     * Mark every unused token of a user as used (after a reset or a new request).
     */
    public function invalidateForUser(int $userId): int
    {
        return $this->db->execute(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
    }
}
