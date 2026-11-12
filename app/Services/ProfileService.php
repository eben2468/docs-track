<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Core\Auth;
use Core\Csrf;
use Core\Session;

/**
 * A user's own profile: contact details and password (FR-1).
 */
final class ProfileService
{
    public function __construct(
        private readonly User $users = new User(),
        private readonly AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * Update the user's name and phone number.
     */
    public function updateDetails(int $userId, string $fullName, ?string $phone): void
    {
        $before = $this->users->find($userId) ?? throw new ServiceException('Account not found.');
        $after = ['full_name' => $fullName, 'phone' => $phone];

        $changes = AuditService::diff($before, $after);
        if ($changes === []) {
            return;
        }

        $this->users->update($userId, $after);
        $this->audit->log('profile.updated', ['changes' => $changes]);
        Auth::forgetCachedUser();
    }

    /**
     * Change the password after confirming the current one. Issues a new session ID.
     *
     * @throws ServiceException when the current password is wrong or the new one is unchanged
     */
    public function changePassword(int $userId, string $current, string $new): void
    {
        $user = $this->users->find($userId) ?? throw new ServiceException('Account not found.');

        if (!password_verify($current, (string) $user['password_hash'])) {
            throw new ServiceException('Your current password is incorrect.', 'current_password');
        }
        if (password_verify($new, (string) $user['password_hash'])) {
            throw new ServiceException('The new password must be different from your current password.', 'password');
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $this->users->update($userId, ['password_hash' => $hash]);
        $this->audit->log('password.changed');

        // New session ID for this browser; every other session of this account ends.
        Session::regenerate();
        Csrf::regenerate();
        Auth::refreshFingerprint($hash);
    }
}
