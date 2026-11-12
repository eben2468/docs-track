<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Core\Request;
use Core\Response;

/**
 * GET /api/departments/{id}/users: active members of an active department,
 * used to fill the optional recipient list on the send form.
 */
final class DepartmentUserController extends Controller
{
    public function index(Request $request, string $id): Response
    {
        $department = (new Department())->find((int) $id);
        if ($department === null || (int) $department['is_active'] !== 1) {
            return Response::json(['error' => 'Department not found.'], 404);
        }

        $users = array_map(
            static fn (array $u): array => ['id' => (int) $u['id'], 'name' => $u['full_name'], 'role' => role_label($u['role'])],
            (new User())->inDepartment((int) $department['id'])
        );

        return Response::json(['department' => $department['name'], 'users' => $users])
            ->header('Cache-Control', 'private, no-store');
    }
}
