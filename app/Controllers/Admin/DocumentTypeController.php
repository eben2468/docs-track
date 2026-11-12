<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Models\DocumentType;
use App\Services\DocumentTypeService;
use App\Services\ServiceException;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Admin: document types and their SLA (FR-3).
 */
final class DocumentTypeController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $this->queryString($request, 'q');
        $status = $this->queryString($request, 'status');

        return $this->view('admin/document-types/index', [
            'title' => 'Document types',
            'result' => (new DocumentType())->search($q, $status, $this->page($request)),
            'filters' => ['q' => $q, 'status' => $status],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin/document-types/form', ['title' => 'New document type', 'type' => null]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, null);

        try {
            (new DocumentTypeService())->create($data);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/document-types/create');
        }

        return Response::redirect('/admin/document-types')->with('success', "Document type \"{$data['name']}\" created.");
    }

    public function edit(Request $request, string $id): Response
    {
        $type = $this->findOr404((int) $id);

        return $this->view('admin/document-types/form', ['title' => 'Edit ' . $type['name'], 'type' => $type]);
    }

    public function update(Request $request, string $id): Response
    {
        $type = $this->findOr404((int) $id);
        $data = $this->validated($request, (int) $type['id']);

        try {
            (new DocumentTypeService())->update((int) $type['id'], $data);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, "/admin/document-types/{$type['id']}/edit");
        }

        return Response::redirect('/admin/document-types')->with('success', "Document type \"{$data['name']}\" updated.");
    }

    public function status(Request $request, string $id): Response
    {
        $type = $this->findOr404((int) $id);
        $active = $request->input('active') === '1';

        try {
            (new DocumentTypeService())->setActive((int) $type['id'], $active);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/document-types');
        }

        return $this->back($request, '/admin/document-types')
            ->with('success', "\"{$type['name']}\" " . ($active ? 'activated.' : 'deactivated.'));
    }

    /**
     * @return array{name: string, sla_days: int}
     */
    private function validated(Request $request, ?int $id): array
    {
        $ignore = $id ?? 'NULL';
        $data = $this->validate($request, [
            'name' => ['required', 'max:100', "unique:document_types,name,$ignore"],
            'sla_days' => 'required|integer|min:1|max:60',
        ], ['sla_days' => 'SLA (working days)']);

        return ['name' => (string) $data['name'], 'sla_days' => (int) $data['sla_days']];
    }

    /**
     * @return array<string, mixed>
     */
    private function findOr404(int $id): array
    {
        return (new DocumentType())->find($id) ?? throw new HttpException(404);
    }
}
