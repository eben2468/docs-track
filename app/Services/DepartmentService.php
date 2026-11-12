<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use Core\Database;

/**
 * Department management (FR-2). Departments are deactivated, never deleted.
 */
final class DepartmentService
{
    public function __construct(
        private readonly Department $departments = new Department(),
        private readonly User $users = new User(),
        private readonly AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * Create a department. Returns its ID.
     *
     * @param array{name: string, code: string, parent_id: ?int, location: ?string, email: ?string} $data
     */
    public function create(array $data): int
    {
        $data['code'] = strtoupper($data['code']);
        $this->assertValidParent(null, $data['parent_id']);

        return Database::instance()->transaction(function () use ($data): int {
            $id = $this->departments->insert($data);
            $this->audit->log('department.created', ['department_id' => $id, 'code' => $data['code'], 'name' => $data['name']]);

            return $id;
        });
    }

    /**
     * Update a department, including its parent and head of department.
     * A staff member chosen as HOD is promoted to the "hod" role.
     *
     * @param array{name: string, code: string, parent_id: ?int, head_user_id: ?int, location: ?string, email: ?string} $data
     */
    public function update(int $id, array $data): void
    {
        $data['code'] = strtoupper($data['code']);

        Database::instance()->transaction(function () use ($id, $data): void {
            $before = $this->departments->find($id) ?? throw new ServiceException('Department not found.');
            $this->assertValidParent($id, $data['parent_id']);

            if ($data['head_user_id'] !== null) {
                $head = $this->users->find($data['head_user_id']);
                if ($head === null || (int) $head['department_id'] !== $id || (int) $head['is_active'] !== 1) {
                    throw new ServiceException('The head of department must be an active member of this department.', 'head_user_id');
                }
                if ($head['role'] === 'staff') {
                    $this->users->update((int) $head['id'], ['role' => 'hod']);
                    $this->audit->log('user.updated', [
                        'user_id' => (int) $head['id'],
                        'changes' => ['role' => ['staff', 'hod']],
                        'reason' => 'appointed head of department',
                    ]);
                }
            }

            $changes = AuditService::diff($before, $data);
            if ($changes === []) {
                return;
            }
            $this->departments->update($id, $data);
            $this->audit->log('department.updated', ['department_id' => $id, 'changes' => $changes]);
        });
    }

    /**
     * Activate or deactivate a department. A department with active users or open
     * documents cannot be deactivated.
     */
    public function setActive(int $id, bool $active): void
    {
        Database::instance()->transaction(function () use ($id, $active): void {
            $department = $this->departments->find($id) ?? throw new ServiceException('Department not found.');
            if ((bool) $department['is_active'] === $active) {
                return;
            }

            if (!$active) {
                $users = $this->departments->activeUserCount($id);
                if ($users > 0) {
                    throw new ServiceException("This department still has $users active user(s). Move or deactivate them first.");
                }
                $documents = $this->departments->openDocumentCount($id);
                if ($documents > 0) {
                    throw new ServiceException("This department still holds or is receiving $documents document(s).");
                }
            }

            $this->departments->update($id, ['is_active' => $active ? 1 : 0]);
            $this->audit->log($active ? 'department.activated' : 'department.deactivated', [
                'department_id' => $id,
                'code' => $department['code'],
            ]);
        });
    }

    /**
     * The parent must exist, be active, and not be the department itself or one of its descendants.
     */
    private function assertValidParent(?int $id, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = $this->departments->find($parentId);
        if ($parent === null || (int) $parent['is_active'] !== 1) {
            throw new ServiceException('The parent department must be an active department.', 'parent_id');
        }

        $seen = [];
        for ($current = $parent; $current !== null; $current = $current['parent_id'] ? $this->departments->find((int) $current['parent_id']) : null) {
            $currentId = (int) $current['id'];
            if ($currentId === $id) {
                throw new ServiceException('A department cannot be placed under itself or one of its sub-departments.', 'parent_id');
            }
            if (isset($seen[$currentId])) {
                break;
            }
            $seen[$currentId] = true;
        }
    }
}
