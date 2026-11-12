<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use Core\Auth;
use Core\Database;

/**
 * User management (FR-2). Users are deactivated, never deleted.
 */
final class UserService
{
    public function __construct(
        private readonly User $users = new User(),
        private readonly Department $departments = new Department(),
        private readonly PasswordResetService $passwords = new PasswordResetService(),
        private readonly AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * Create a user. With no password given, a random one is set and a
     * "set your password" link is emailed. Returns the new user's ID.
     *
     * @param array{staff_id: string, full_name: string, email: string, phone: ?string, department_id: int, role: string} $data
     */
    public function create(array $data, ?string $password, string $source = 'form'): int
    {
        $this->assertActiveDepartment($data['department_id']);

        return Database::instance()->transaction(function () use ($data, $password, $source): int {
            $id = $this->users->insert($data + [
                'password_hash' => password_hash($password ?? bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'is_active' => 1,
            ]);

            if ($password === null) {
                $this->passwords->sendLink(['id' => $id] + $data, 'welcome');
            }

            $this->audit->log('user.created', [
                'user_id' => $id,
                'staff_id' => $data['staff_id'],
                'email' => $data['email'],
                'role' => $data['role'],
                'department_id' => $data['department_id'],
                'source' => $source,
                'welcome_email' => $password === null,
            ]);

            return $id;
        });
    }

    /**
     * Update a user's details, and optionally set a new password.
     *
     * @param array{staff_id: string, full_name: string, email: string, phone: ?string, department_id: int, role: string} $data
     */
    public function update(int $id, array $data, ?string $newPassword): void
    {
        Database::instance()->transaction(function () use ($id, $data, $newPassword): void {
            $before = $this->users->find($id) ?? throw new ServiceException('User not found.');

            if ($id === Auth::id() && $data['role'] !== $before['role']) {
                throw new ServiceException('You cannot change your own role.', 'role');
            }
            if ($before['role'] === 'admin' && $data['role'] !== 'admin') {
                $this->assertNotLastAdmin($before);
            }
            if ((int) $data['department_id'] !== (int) $before['department_id']) {
                $this->assertActiveDepartment($data['department_id']);
            }

            $changes = AuditService::diff($before, $data);
            if ($changes !== []) {
                $this->users->update($id, $data);

                $leftDepartment = isset($changes['department_id']);
                $nowStaff = $data['role'] === 'staff';
                if ($leftDepartment || $nowStaff) {
                    $this->clearHeadOf($id, (int) $before['department_id']);
                }
            }

            if ($newPassword !== null) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $this->users->update($id, [
                    'password_hash' => $hash,
                    'failed_attempts' => 0,
                    'locked_until' => null,
                ]);
                // The user's existing sessions end; an admin editing themselves stays signed in.
                if ($id === Auth::id()) {
                    Auth::refreshFingerprint($hash);
                }
            }

            if ($changes !== [] || $newPassword !== null) {
                $this->audit->log('user.updated', array_filter([
                    'user_id' => $id,
                    'changes' => $changes,
                    'password_set' => $newPassword !== null ?: null,
                ]));
            }
        });

        if ($id === Auth::id()) {
            Auth::forgetCachedUser();
        }
    }

    /**
     * Activate or deactivate a user. You cannot deactivate yourself or the last active admin.
     */
    public function setActive(int $id, bool $active): void
    {
        Database::instance()->transaction(function () use ($id, $active): void {
            $user = $this->users->find($id) ?? throw new ServiceException('User not found.');
            if ((bool) $user['is_active'] === $active) {
                return;
            }

            if (!$active) {
                if ($id === Auth::id()) {
                    throw new ServiceException('You cannot deactivate your own account.');
                }
                if ($user['role'] === 'admin') {
                    $this->assertNotLastAdmin($user);
                }
                $this->clearHeadOf($id, (int) $user['department_id']);
            } else {
                $this->assertActiveDepartment((int) $user['department_id']);
            }

            $this->users->update($id, ['is_active' => $active ? 1 : 0]);
            $this->audit->log($active ? 'user.activated' : 'user.deactivated', ['user_id' => $id, 'staff_id' => $user['staff_id']]);
        });
    }

    /**
     * Clear a lockout caused by failed logins.
     */
    public function unlock(int $id): void
    {
        $user = $this->users->find($id) ?? throw new ServiceException('User not found.');
        $this->users->update($id, ['failed_attempts' => 0, 'locked_until' => null]);
        $this->audit->log('user.unlocked', ['user_id' => $id, 'staff_id' => $user['staff_id']]);
    }

    /**
     * Email the user a fresh "set your password" link.
     */
    public function sendPasswordLink(int $id): void
    {
        $user = $this->users->find($id) ?? throw new ServiceException('User not found.');
        if ((int) $user['is_active'] !== 1) {
            throw new ServiceException('Activate the account before sending a password link.');
        }
        $this->passwords->sendLink($user, 'reset');
        $this->audit->log('user.password_link_sent', ['user_id' => $id]);
    }

    private function assertActiveDepartment(int $departmentId): void
    {
        $department = $this->departments->find($departmentId);
        if ($department === null || (int) $department['is_active'] !== 1) {
            throw new ServiceException('Please choose an active department.', 'department_id');
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertNotLastAdmin(array $user): void
    {
        if ((int) $user['is_active'] === 1 && $this->users->activeAdminCount() <= 1) {
            throw new ServiceException('This is the only active administrator. Make another user an admin first.');
        }
    }

    /**
     * Remove the user as head of their (former) department, if they were.
     */
    private function clearHeadOf(int $userId, int $departmentId): void
    {
        $department = $this->departments->find($departmentId);
        if ($department !== null && (int) ($department['head_user_id'] ?? 0) === $userId) {
            $this->departments->update($departmentId, ['head_user_id' => null]);
            $this->audit->log('department.updated', [
                'department_id' => $departmentId,
                'changes' => ['head_user_id' => [$userId, null]],
                'reason' => 'head of department removed',
            ]);
        }
    }
}
