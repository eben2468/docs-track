<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\ServiceException;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Admin: departments (FR-2).
 */
final class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $this->queryString($request, 'q');
        $status = $this->queryString($request, 'status');

        return $this->view('admin/departments/index', [
            'title' => 'Departments',
            'result' => (new Department())->search($q, $status, $this->page($request)),
            'filters' => ['q' => $q, 'status' => $status],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin/departments/form', [
            'title' => 'New department',
            'department' => null,
            'parents' => (new Department())->active(),
            'members' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, null);

        try {
            (new DepartmentService())->create([
                'name' => $data['name'],
                'code' => $data['code'],
                'parent_id' => $data['parent_id'],
                'location' => $data['location'],
                'email' => $data['email'],
            ]);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/departments/create');
        }

        return Response::redirect('/admin/departments')->with('success', "Department {$data['code']} created.");
    }

    public function edit(Request $request, string $id): Response
    {
        $department = $this->findOr404((int) $id);

        return $this->view('admin/departments/form', [
            'title' => 'Edit ' . $department['name'],
            'department' => $department,
            'parents' => array_values(array_filter(
                (new Department())->active(),
                static fn (array $d): bool => (int) $d['id'] !== (int) $department['id']
            )),
            'members' => (new User())->inDepartment((int) $department['id']),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $department = $this->findOr404((int) $id);
        $data = $this->validated($request, (int) $department['id']);

        try {
            (new DepartmentService())->update((int) $department['id'], $data);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, "/admin/departments/{$department['id']}/edit");
        }

        return Response::redirect('/admin/departments')->with('success', "Department {$data['code']} updated.");
    }

    public function status(Request $request, string $id): Response
    {
        $department = $this->findOr404((int) $id);
        $active = $request->input('active') === '1';

        try {
            (new DepartmentService())->setActive((int) $department['id'], $active);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/departments');
        }

        return $this->back($request, '/admin/departments')
            ->with('success', "Department {$department['code']} " . ($active ? 'activated.' : 'deactivated.'));
    }

    /**
     * @return array{name: string, code: string, parent_id: ?int, head_user_id: ?int, location: ?string, email: ?string}
     */
    private function validated(Request $request, ?int $id): array
    {
        $ignore = $id ?? 'NULL';
        $data = $this->validate($request, [
            'name' => 'required|max:150',
            'code' => ['required', 'regex:/^[A-Za-z]{2,6}$/', "unique:departments,code,$ignore"],
            'parent_id' => 'integer|exists:departments,id',
            'head_user_id' => 'integer|exists:users,id',
            'location' => 'max:150',
            'email' => 'email|max:150',
        ], ['code' => 'Code (2–6 letters)', 'parent_id' => 'Parent department', 'head_user_id' => 'Head of department']);

        return [
            'name' => (string) $data['name'],
            'code' => strtoupper((string) $data['code']),
            'parent_id' => $data['parent_id'] === null ? null : (int) $data['parent_id'],
            'head_user_id' => ($id === null || $data['head_user_id'] === null) ? null : (int) $data['head_user_id'],
            'location' => $data['location'],
            'email' => $data['email'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findOr404(int $id): array
    {
        return (new Department())->find($id) ?? throw new HttpException(404);
    }
}
