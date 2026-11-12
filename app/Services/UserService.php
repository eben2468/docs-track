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
        private readonly MailService $mail = new MailService(),
        private readonly AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * Create a user and queue a welcome email with their username. Users sign in with
     * a one-time code emailed to them, so there is no password to set. The stored
     * password_hash is a random secret used only to fingerprint sessions. Returns the new ID.
     *
     * @param array{staff_id: string, username: string, full_name: string, email: string, phone: ?string, department_id: int, role: string} $data
     */
    public function create(array $data, string $source = 'form'): int
    {
        $this->assertActiveDepartment($data['department_id']);

        return Database::instance()->transaction(function () use ($data, $source): int {
            $id = $this->users->insert($data + [
                'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'is_active' => 1,
            ]);

            $this->mail->queueView($data['email'], 'Your account on ' . setting('app_name'), 'emails/welcome', [
                'name' => $data['full_name'],
                'username' => $data['username'],
                'link' => url('/login'),
            ]);

            $this->audit->log('user.created', [
                'user_id' => $id,
                'staff_id' => $data['staff_id'],
                'username' => $data['username'],
                'email' => $data['email'],
                'role' => $data['role'],
                'department_id' => $data['department_id'],
                'source' => $source,
            ]);

            return $id;
        });
    }

    /**
     * Update a user's details (including their username).
     *
     * @param array{staff_id: string, username: string, full_name: string, email: string, phone: ?string, department_id: int, role: string} $data
     */
    public function update(int $id, array $data): void
    {
        Database::instance()->transaction(function () use ($id, $data): void {
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

            if ($changes !== []) {
                $this->audit->log('user.updated', ['user_id' => $id, 'changes' => $changes]);
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
