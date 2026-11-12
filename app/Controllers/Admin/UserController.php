<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\ServiceException;
use App\Services\UserService;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Admin: users (FR-2).
 */
final class UserController extends Controller
{
    /** Usernames: letters, digits, dot, underscore and hyphen (stored lower-case). */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9._-]+$/';

    public function index(Request $request): Response
    {
        $filters = [
            'q' => $this->queryString($request, 'q'),
            'department' => $this->queryString($request, 'department'),
            'role' => $this->queryString($request, 'role'),
            'status' => $this->queryString($request, 'status'),
        ];
        $departmentId = ctype_digit($filters['department']) ? (int) $filters['department'] : null;

        return $this->view('admin/users/index', [
            'title' => 'Users',
            'result' => (new User())->search($filters['q'], $departmentId, $filters['role'], $filters['status'], $this->page($request)),
            'filters' => $filters,
            'departments' => (new Department())->all('name ASC'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin/users/form', [
            'title' => 'New user',
            'user' => null,
            'departments' => (new Department())->active(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, null);

        try {
            (new UserService())->create($data);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/users/create');
        }

        return Response::redirect('/admin/users')->with(
            'success',
            "User {$data['full_name']} created with username \"{$data['username']}\". A welcome email has been queued."
        );
    }

    public function edit(Request $request, string $id): Response
    {
        $user = $this->findOr404((int) $id);
        $departments = array_values(array_filter(
            (new Department())->all('name ASC'),
            static fn (array $d): bool => (int) $d['is_active'] === 1 || (int) $d['id'] === (int) $user['department_id']
        ));

        return $this->view('admin/users/form', [
            'title' => 'Edit ' . $user['full_name'],
            'user' => $user,
            'departments' => $departments,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $user = $this->findOr404((int) $id);
        $data = $this->validated($request, (int) $user['id']);

        try {
            (new UserService())->update((int) $user['id'], $data);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, "/admin/users/{$user['id']}/edit");
        }

        return Response::redirect('/admin/users')->with('success', "User {$data['full_name']} updated.");
    }

    public function status(Request $request, string $id): Response
    {
        $user = $this->findOr404((int) $id);
        $active = $request->input('active') === '1';

        try {
            (new UserService())->setActive((int) $user['id'], $active);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/users');
        }

        return $this->back($request, '/admin/users')
            ->with('success', "{$user['full_name']} " . ($active ? 'activated.' : 'deactivated.'));
    }

    public function unlock(Request $request, string $id): Response
    {
        $user = $this->findOr404((int) $id);
        (new UserService())->unlock((int) $user['id']);

        return $this->back($request, '/admin/users')->with('success', "{$user['full_name']}'s account has been unlocked.");
    }


    /**
     * @return array{staff_id: string, username: string, full_name: string, email: string, phone: ?string, department_id: int, role: string}
     */
    private function validated(Request $request, ?int $id): array
    {
        $ignore = $id ?? 'NULL';
        $data = $this->validate($request, [
            'staff_id' => ['required', 'max:30', "unique:users,staff_id,$ignore"],
            'username' => ['required', 'min:3', 'max:50', 'regex:' . self::USERNAME_PATTERN, "unique:users,username,$ignore"],
            'full_name' => 'required|max:150',
            'email' => ['required', 'email', 'max:150', "unique:users,email,$ignore"],
            'phone' => ['max:20', 'regex:/^[0-9+()\s-]*$/'],
            'department_id' => 'required|integer|exists:departments,id',
            'role' => 'required|in:admin,hod,staff',
        ], ['staff_id' => 'Staff ID', 'username' => 'Username', 'full_name' => 'Full name', 'department_id' => 'Department']);

        return [
            'staff_id' => (string) $data['staff_id'],
            'username' => strtolower((string) $data['username']),
            'full_name' => (string) $data['full_name'],
            'email' => strtolower((string) $data['email']),
            'phone' => $data['phone'],
            'department_id' => (int) $data['department_id'],
            'role' => (string) $data['role'],
        ];
    }


    /**
     * @return array<string, mixed>
     */
    private function findOr404(int $id): array
    {
        return (new User())->find($id) ?? throw new HttpException(404);
    }
}
