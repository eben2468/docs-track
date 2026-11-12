<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Request log used to rate-limit login attempts, password-reset requests and public tracking per IP.
 */
final class LoginThrottle extends Model
{
    protected string $table = 'login_throttle';

    /** @var list<string> */
    protected array $fillable = ['ip_address', 'scope'];

    /**
     * Number of hits from an IP for a scope within the last $minutes.
     */
    public function recentCount(string $ip, string $scope, int $minutes): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_throttle
             WHERE ip_address = ? AND scope = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$ip, $scope, $minutes]
        );
    }

    /**
     * Minutes until the oldest hit in the window expires (at least 1).
     */
    public function minutesUntilReset(string $ip, string $scope, int $minutes): int
    {
        $seconds = (int) $this->db->fetchValue(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MIN(created_at), INTERVAL ? MINUTE))
             FROM login_throttle
             WHERE ip_address = ? AND scope = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$minutes, $ip, $scope, $minutes]
        );

        return max(1, (int) ceil($seconds / 60));
    }

    /**
     * Delete hits older than $hours (no window is longer than an hour). Returns rows removed.
     */
    public function purgeOlderThan(int $hours = 24): int
    {
        return $this->db->execute('DELETE FROM login_throttle WHERE created_at < (NOW() - INTERVAL ' . max(1, $hours) . ' HOUR)');
    }

    public function record(string $ip, string $scope): void
    {
        $this->insert(['ip_address' => $ip, 'scope' => $scope]);
    }
}
