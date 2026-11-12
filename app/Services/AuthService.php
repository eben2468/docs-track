<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginOtp;
use App\Models\LoginThrottle;
use App\Models\User;
use Core\Auth;
use Core\Logger;

/**
 * Passwordless sign-in (FR-1): the user enters the username an administrator gave them,
 * a one-time code is emailed to their address, and they enter that code to sign in.
 * Per-account lockout, per-IP throttles and auditing as before.
 *
 * The "send code" step never reveals whether a username exists: the response is the same
 * for unknown, deactivated and locked accounts (no email is sent to those).
 */
final class AuthService
{
    /** Wrong codes before an account is locked (spec: 5). */
    public const MAX_ATTEMPTS = 5;

    /** Lock duration in minutes (spec: 15). */
    public const LOCK_MINUTES = 15;

    /**
     * Failed sign-ins (and code requests) allowed per IP per window. Set high because campus
     * users share NAT addresses; the per-account lock is the main brute-force protection.
     */
    public const IP_LIMIT = 30;

    public const IP_WINDOW_MINUTES = 15;

    /** How long an emailed code stays valid. */
    public const CODE_MINUTES = 10;

    public const CODE_LENGTH = 6;

    /** Codes emailed to one account per window (stops someone flooding a user's inbox). */
    public const CODES_PER_WINDOW = 5;

    /** Letters and digits that cannot be confused with each other (no 0/O, 1/l/I). */
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const DIGITS = '23456789';

    /** Symbols that survive copy/paste and are easy to read in an email. */
    private const SYMBOLS = '@#$%&*?!';

    private const GENERIC_ERROR = 'That code is incorrect or has expired. Check the latest email, or request a new code.';

    public function __construct(
        private readonly User $users = new User(),
        private readonly LoginOtp $otps = new LoginOtp(),
        private readonly LoginThrottle $throttle = new LoginThrottle(),
        private readonly AuditService $audit = new AuditService(),
        private readonly MailService $mail = new MailService(),
    ) {
    }

    /**
     * Step 1: email a one-time code to the account with this username, if it exists and
     * may sign in. Silent for unknown, deactivated or locked accounts.
     *
     * @throws ServiceException only when this network has made too many requests
     */
    public function requestCode(string $username, string $ip): void
    {
        $this->guardIp($ip, 'otp', $username);
        $this->throttle->record($ip, 'otp');

        $user = $this->users->findByUsername($username);
        if ($user === null) {
            $this->audit->log('login.code_unknown_user', ['username' => mb_substr($username, 0, 50)]);

            return;
        }

        $userId = (int) $user['id'];
        $reason = match (true) {
            (int) $user['is_active'] !== 1 => 'inactive',
            $this->isLocked($user) => 'locked',
            $this->otps->issuedSince($userId, self::IP_WINDOW_MINUTES) >= self::CODES_PER_WINDOW => 'too_many_codes',
            default => null,
        };
        if ($reason !== null) {
            $this->audit->log('login.code_refused', ['reason' => $reason], null, $userId);

            return;
        }

        $code = self::generateCode();
        $this->otps->invalidateAll($userId);
        $this->otps->insert([
            'user_id' => $userId,
            'code_hash' => self::hashCode($code),
            'ip_address' => $ip,
            'expires_at' => now()->modify('+' . self::CODE_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
        ]);
        $this->sendCode($user, $code, $ip);
        $this->audit->log('login.code_sent', [], null, $userId);
    }

    /**
     * Step 2: check the emailed code and sign the user in.
     *
     * @return array<string, mixed> The authenticated user row
     * @throws ServiceException with a user-safe message on any failure
     */
    public function verifyCode(string $username, string $code, string $ip): array
    {
        $this->guardIp($ip, 'login', $username);

        $user = $this->users->findByUsername($username);
        if ($user !== null && $this->isLocked($user)) {
            $this->audit->log('login.locked', ['username' => $username], null, (int) $user['id']);
            throw new ServiceException(sprintf(
                'This account is locked after too many incorrect codes. Try again in %d minute(s) or contact the system administrator.',
                $this->minutesLeft((string) $user['locked_until'])
            ));
        }

        $otp = $user !== null && (int) $user['is_active'] === 1 ? $this->otps->latestValid((int) $user['id']) : null;
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($otp === null || !hash_equals((string) $otp['code_hash'], self::hashCode($code))) {
            $this->recordFailure($user, $username, $ip);
            throw new ServiceException(self::GENERIC_ERROR);
        }
        if (!$this->otps->markUsed((int) $otp['id'])) {
            throw new ServiceException(self::GENERIC_ERROR);
        }

        $userId = (int) $user['id'];
        $this->users->update($userId, ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()->format('Y-m-d H:i:s')]);
        Auth::login($userId, (string) $user['password_hash']);
        $this->audit->log('login', ['method' => 'email_code'], null, $userId);

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
     * A random one-time code: CODE_LENGTH characters with at least one uppercase letter,
     * one lowercase letter and one digit, and (about half the time) one symbol. Case-sensitive.
     */
    public static function generateCode(): string
    {
        $pick = static fn (string $set): string => $set[random_int(0, strlen($set) - 1)];
        $all = self::UPPER . self::LOWER . self::DIGITS;

        $chars = [$pick(self::UPPER), $pick(self::LOWER), $pick(self::DIGITS)];
        if (random_int(0, 1) === 1) {
            $chars[] = $pick(self::SYMBOLS);
        }
        while (count($chars) < self::CODE_LENGTH) {
            $chars[] = $pick($all);
        }

        // Fisher-Yates shuffle with a cryptographic RNG.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * Email the code right away (users are waiting for it). If sending fails, it is queued
     * so the email cron retries it.
     *
     * @param array<string, mixed> $user
     */
    private function sendCode(array $user, string $code, string $ip): void
    {
        $subject = 'Your sign-in code for ' . setting('app_name');
        $html = MailService::render('emails/login-code', $subject, [
            'name' => (string) $user['full_name'],
            'username' => (string) $user['username'],
            'code' => $code,
            'minutes' => self::CODE_MINUTES,
            'ip' => $ip,
        ]);

        try {
            $this->mail->sendNow((string) $user['email'], $subject, $html);
        } catch (\Throwable $e) {
            Logger::error('Sign-in code email failed; queued for retry.', ['user_id' => $user['id'], 'error' => $e->getMessage()]);
            $this->mail->queue((string) $user['email'], $subject, $html);
        }
    }

    /**
     * @throws ServiceException when this IP has used up its allowance for the scope
     */
    private function guardIp(string $ip, string $scope, string $username): void
    {
        if ($this->throttle->recentCount($ip, $scope, self::IP_WINDOW_MINUTES) >= self::IP_LIMIT) {
            $wait = $this->throttle->minutesUntilReset($ip, $scope, self::IP_WINDOW_MINUTES);
            $this->audit->log('login.throttled', ['username' => mb_substr($username, 0, 50), 'scope' => $scope]);
            throw new ServiceException("Too many sign-in attempts from your network. Please try again in $wait minute(s).");
        }
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function recordFailure(?array $user, string $username, string $ip): void
    {
        $this->throttle->record($ip, 'login');

        $details = ['username' => mb_substr($username, 0, 50)];
        $userId = null;
        if ($user !== null) {
            $userId = (int) $user['id'];
            if ($this->users->registerFailedLogin($userId, self::MAX_ATTEMPTS, self::LOCK_MINUTES)) {
                $details['locked'] = true;
                $this->otps->invalidateAll($userId);
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
