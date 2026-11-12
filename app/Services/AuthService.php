<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginThrottle;
use App\Models\User;
use Core\Auth;

/**
 * Login and logout (FR-1): email or staff ID, per-account lockout, per-IP throttle, auditing.
 */
final class AuthService
{
    /** Failed attempts before an account is locked (spec: 5). */
    public const MAX_ATTEMPTS = 5;

    /** Lock duration in minutes (spec: 15). */
    public const LOCK_MINUTES = 15;

    /**
     * Failed logins allowed per IP per window. Set high because campus users share
     * NAT addresses; the per-account lock is the main brute-force protection.
     */
    public const IP_LIMIT = 30;

    public const IP_WINDOW_MINUTES = 15;

    private const GENERIC_ERROR = 'Invalid email/staff ID or password.';

    public function __construct(
        private readonly User $users = new User(),
        private readonly LoginThrottle $throttle = new LoginThrottle(),
        private readonly AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * Check credentials and log the user in.
     *
     * @return array<string, mixed> The authenticated user row
     * @throws ServiceException with a user-safe message on any failure
     */
    public function attempt(string $login, string $password, string $ip): array
    {
        if ($this->throttle->recentCount($ip, 'login', self::IP_WINDOW_MINUTES) >= self::IP_LIMIT) {
            $wait = $this->throttle->minutesUntilReset($ip, 'login', self::IP_WINDOW_MINUTES);
            $this->audit->log('login.throttled', ['login' => $login]);
            throw new ServiceException("Too many failed sign-in attempts from your network. Please try again in $wait minute(s).");
        }

        $user = $this->users->findByLogin($login);

        if ($user !== null && $this->isLocked($user)) {
            $this->audit->log('login.locked', ['login' => $login], null, (int) $user['id']);
            throw new ServiceException(sprintf(
                'This account is locked after too many failed attempts. Try again in %d minute(s) or reset your password.',
                $this->minutesLeft((string) $user['locked_until'])
            ));
        }

        if ($user === null) {
            // Spend the same time as a real check so response timing does not reveal unknown accounts.
            password_hash($password, PASSWORD_DEFAULT);
            $this->recordFailure(null, $login, $ip);
            throw new ServiceException(self::GENERIC_ERROR);
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->recordFailure($user, $login, $ip);
            throw new ServiceException(self::GENERIC_ERROR);
        }

        if ((int) $user['is_active'] !== 1) {
            $this->audit->log('login.failed', ['login' => $login, 'reason' => 'inactive'], null, (int) $user['id']);
            throw new ServiceException('Your account has been deactivated. Please contact the system administrator.');
        }

        $update = ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()->format('Y-m-d H:i:s')];
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $this->users->update((int) $user['id'], $update);

        Auth::login((int) $user['id'], (string) ($update['password_hash'] ?? $user['password_hash']));
        $this->audit->log('login', [], null, (int) $user['id']);

        return $user;
    }

    /**
     * Log the current user out.
     */
    public function logout(): void
    {
        if (Auth::id() !== null) {
            $this->audit->log('logout');
        }
        Auth::logout();
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function recordFailure(?array $user, string $login, string $ip): void
    {
        $this->throttle->record($ip, 'login');

        $details = ['login' => mb_substr($login, 0, 150)];
        $userId = null;
        if ($user !== null) {
            $userId = (int) $user['id'];
            if ($this->users->registerFailedLogin($userId, self::MAX_ATTEMPTS, self::LOCK_MINUTES)) {
                $details['locked'] = true;
            }
        }

        $this->audit->log('login.failed', $details, null, $userId);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isLocked(array $user): bool
    {
        return $user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time();
    }

    private function minutesLeft(string $lockedUntil): int
    {
        return max(1, (int) ceil((strtotime($lockedUntil) - time()) / 60));
    }
}
