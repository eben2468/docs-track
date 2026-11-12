<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Models\Holiday;
use App\Services\HolidayService;
use App\Services\ServiceException;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Admin: public holidays excluded from working-day calculations.
 */
final class HolidayController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) $this->queryString($request, 'year');
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) now()->format('Y');
        $holidays = new Holiday();
        $years = array_values(array_unique([...$holidays->years(), (int) now()->format('Y'), $year]));
        rsort($years);

        return $this->view('admin/holidays/index', [
            'title' => 'Holidays',
            'year' => $year,
            'years' => $years,
            'holidays' => $holidays->forYear($year),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin/holidays/form', ['title' => 'New holiday', 'holiday' => null]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, null);

        try {
            (new HolidayService())->create($data);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/holidays/create');
        }

        return Response::redirect('/admin/holidays?year=' . substr($data['holiday_date'], 0, 4))
            ->with('success', "Holiday \"{$data['name']}\" added.");
    }

    public function edit(Request $request, string $id): Response
    {
        $holiday = $this->findOr404((int) $id);

        return $this->view('admin/holidays/form', ['title' => 'Edit ' . $holiday['name'], 'holiday' => $holiday]);
    }

    public function update(Request $request, string $id): Response
    {
        $holiday = $this->findOr404((int) $id);
        $data = $this->validated($request, (int) $holiday['id']);

        try {
            (new HolidayService())->update((int) $holiday['id'], $data);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, "/admin/holidays/{$holiday['id']}/edit");
        }

        return Response::redirect('/admin/holidays?year=' . substr($data['holiday_date'], 0, 4))
            ->with('success', "Holiday \"{$data['name']}\" updated.");
    }

    public function destroy(Request $request, string $id): Response
    {
        $holiday = $this->findOr404((int) $id);
        (new HolidayService())->delete((int) $holiday['id']);

        return Response::redirect('/admin/holidays?year=' . substr((string) $holiday['holiday_date'], 0, 4))
            ->with('success', "Holiday \"{$holiday['name']}\" deleted.");
    }

    /**
     * @return array{holiday_date: string, name: string}
     */
    private function validated(Request $request, ?int $id): array
    {
        $ignore = $id ?? 'NULL';
        $data = $this->validate($request, [
            'holiday_date' => ['required', 'date', "unique:holidays,holiday_date,$ignore"],
            'name' => 'required|max:100',
        ], ['holiday_date' => 'Date']);

        return ['holiday_date' => (string) $data['holiday_date'], 'name' => (string) $data['name']];
    }

    /**
     * @return array<string, mixed>
     */
    private function findOr404(int $id): array
    {
        return (new Holiday())->find($id) ?? throw new HttpException(404);
    }
}
