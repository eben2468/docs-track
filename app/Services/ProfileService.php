<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Core\Auth;

/**
 * A user's own profile: contact details (FR-1).
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

}
