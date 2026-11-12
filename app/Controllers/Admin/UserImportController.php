<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Services\ServiceException;
use App\Services\UserImportService;
use Core\Request;
use Core\Response;

/**
 * Admin: bulk user import from CSV (FR-2).
 */
final class UserImportController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->view('admin/users/import', ['title' => 'Import users', 'report' => null]);
    }

    public function import(Request $request): Response
    {
        $this->validate($request, ['file' => 'required|file|mimes:csv,txt|max:1024'], ['file' => 'CSV file']);

        try {
            $result = (new UserImportService())->import((string) $request->file('file')['tmp_name']);
        } catch (ServiceException $e) {
            return Response::redirect('/admin/users/import')->with('danger', $e->getMessage());
        }

        if ($result['imported'] > 0) {
            return Response::redirect('/admin/users')->with(
                'success',
                "{$result['imported']} user(s) imported. Each has been sent a \"set your password\" email."
            );
        }

        $errorRows = count(array_filter($result['rows'], static fn (array $r): bool => $r['errors'] !== []));
        flash('danger', "Nothing was imported: $errorRows of " . count($result['rows']) . ' row(s) have problems. Fix them and upload the file again.');

        return $this->view('admin/users/import', ['title' => 'Import users', 'report' => $result['rows']], status: 422);
    }

    public function template(Request $request): Response
    {
        return (new Response(UserImportService::template()))
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="users-import-template.csv"')
            ->header('Cache-Control', 'no-store');
    }
}
