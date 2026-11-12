<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginThrottle;
use App\Models\PasswordReset;
use App\Models\User;
use Core\Database;
use Core\Logger;

/**
 * Forgot/reset password (FR-1): single-use tokens stored as SHA-256 hashes, valid 60 minutes.
 */
final class PasswordResetService
{
    public const TOKEN_MINUTES = 60;

    /** Reset requests allowed per IP per 15 minutes. */
    public const IP_LIMIT = 5;

    public function __construct(
        private readonly User $users = new User(),
        private readonly PasswordReset $resets = new PasswordReset(),
        private readonly LoginThrottle $throttle = new LoginThrottle(),
        private readonly MailService $mail = new MailService(),
        private readonly AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * Email a reset link if an active account has this address. Behaves the same
     * whether or not the account exists, so addresses cannot be discovered.
     *
     * @throws ServiceException when the IP has made too many requests
     */
    public function requestReset(string $email, string $ip): void
    {
        if ($this->throttle->recentCount($ip, 'password_reset', 15) >= self::IP_LIMIT) {
            throw new ServiceException('Too many reset requests. Please wait a few minutes and try again.');
        }
        $this->throttle->record($ip, 'password_reset');

        $user = $this->users->findByEmail($email);
        if ($user === null || (int) $user['is_active'] !== 1) {
            $this->audit->log('password.reset_requested', ['email' => mb_substr($email, 0, 150), 'matched' => false]);
            return;
        }

        $this->sendLink($user, 'reset');
        $this->audit->log('password.reset_requested', ['matched' => true], null, (int) $user['id']);
    }

    /**
     * Create a token for the user and queue the email. $kind is "reset" (forgot password)
     * or "welcome" (new account set-up). Earlier unused tokens are invalidated.
     *
     * @param array<string, mixed> $user
     */
    public function sendLink(array $user, string $kind = 'reset'): void
    {
        $token = bin2hex(random_bytes(32));

        $this->resets->invalidateForUser((int) $user['id']);
        $this->resets->insert([
            'user_id' => (int) $user['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->modify('+' . self::TOKEN_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
        ]);

        $link = url('/reset-password/' . $token);
        $appName = setting('app_name');
        $subject = $kind === 'welcome' ? "Your $appName account" : "Reset your $appName password";

        $this->mail->queueView((string) $user['email'], $subject, 'emails/password-link', [
            'name' => (string) $user['full_name'],
            'link' => $link,
            'kind' => $kind,
            'minutes' => self::TOKEN_MINUTES,
        ]);

        if (config('app.env') === 'local') {
            Logger::info("Password $kind link for {$user['email']}: $link");
        }
    }

    /**
     * Whether a token from a reset link can still be used.
     */
    public function isTokenValid(string $token): bool
    {
        return self::looksLikeToken($token) && $this->resets->isValid(hash('sha256', $token));
    }

    /**
     * Set a new password using a reset token. The token (and any others for the user)
     * becomes unusable, and any account lock is cleared.
     *
     * @throws ServiceException when the token is invalid, used or expired
     */
    public function reset(string $token, string $newPassword): void
    {
        if (!self::looksLikeToken($token)) {
            throw new ServiceException('This password reset link is invalid or has expired.');
        }

        Database::instance()->transaction(function () use ($token, $newPassword): void {
            $reset = $this->resets->findValidForUpdate(hash('sha256', $token));
            if ($reset === null) {
                throw new ServiceException('This password reset link is invalid or has expired.');
            }

            $userId = (int) $reset['user_id'];
            $this->users->update($userId, [
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'failed_attempts' => 0,
                'locked_until' => null,
            ]);
            $this->resets->invalidateForUser($userId);
            $this->audit->log('password.reset', [], null, $userId);
        });
    }

    private static function looksLikeToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }
}
