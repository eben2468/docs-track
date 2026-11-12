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
        $password = $this->passwordInput($request);

        try {
            (new UserService())->create($data, $password);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/users/create');
        }

        $note = $password === null ? ' A "set your password" email has been queued.' : '';

        return Response::redirect('/admin/users')->with('success', "User {$data['full_name']} created.$note");
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
            (new UserService())->update((int) $user['id'], $data, $this->passwordInput($request));
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

    public function sendPasswordLink(Request $request, string $id): Response
    {
        $user = $this->findOr404((int) $id);

        try {
            (new UserService())->sendPasswordLink((int) $user['id']);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/users');
        }

        return $this->back($request, '/admin/users')->with('success', "A password link has been queued for {$user['email']}.");
    }

    /**
     * @return array{staff_id: string, full_name: string, email: string, phone: ?string, department_id: int, role: string}
     */
    private function validated(Request $request, ?int $id): array
    {
        $ignore = $id ?? 'NULL';
        $data = $this->validate($request, [
            'staff_id' => ['required', 'max:30', "unique:users,staff_id,$ignore"],
            'full_name' => 'required|max:150',
            'email' => ['required', 'email', 'max:150', "unique:users,email,$ignore"],
            'phone' => ['max:20', 'regex:/^[0-9+()\s-]*$/'],
            'department_id' => 'required|integer|exists:departments,id',
            'role' => 'required|in:admin,hod,staff',
            'password' => 'strong_password|max:255|confirmed',
        ], ['staff_id' => 'Staff ID', 'full_name' => 'Full name', 'department_id' => 'Department']);

        return [
            'staff_id' => (string) $data['staff_id'],
            'full_name' => (string) $data['full_name'],
            'email' => strtolower((string) $data['email']),
            'phone' => $data['phone'],
            'department_id' => (int) $data['department_id'],
            'role' => (string) $data['role'],
        ];
    }

    private function passwordInput(Request $request): ?string
    {
        $password = $request->input('password');

        return is_string($password) && $password !== '' ? $password : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function findOr404(int $id): array
    {
        return (new User())->find($id) ?? throw new HttpException(404);
    }
}
