<?php

declare(strict_types=1);

namespace App\Models;

/**
 * One-time sign-in codes emailed to users. Only a SHA-256 hash of each code is stored.
 */
final class LoginOtp extends Model
{
    protected string $table = 'login_otps';

    /** @var list<string> */
    protected array $fillable = ['user_id', 'code_hash', 'ip_address', 'expires_at', 'used_at'];

    /**
     * The user's newest code that is unused and not expired, or null.
     *
     * @return array<string, mixed>|null
     */
    public function latestValid(int $userId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM login_otps WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    /**
     * Codes issued to the user within the last $minutes.
     */
    public function issuedSince(int $userId, int $minutes): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_otps WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$userId, $minutes]
        );
    }

    /**
     * Make every outstanding code of the user unusable (a new code replaces them, or the account locked).
     */
    public function invalidateAll(int $userId): void
    {
        $this->db->execute('UPDATE login_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    /**
     * Mark one code as used, only if it still is unused (guards against double submission).
     */
    public function markUsed(int $id): bool
    {
        return $this->db->execute('UPDATE login_otps SET used_at = NOW() WHERE id = ? AND used_at IS NULL', [$id]) === 1;
    }

    /**
     * Delete codes older than $hours. Returns rows removed.
     */
    public function purgeOlderThan(int $hours = 24): int
    {
        return $this->db->execute('DELETE FROM login_otps WHERE created_at < (NOW() - INTERVAL ' . max(1, $hours) . ' HOUR)');
    }
}
