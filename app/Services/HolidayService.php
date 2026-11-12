<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Holiday;
use Core\Database;

/**
 * Public holidays used by working-day calculations. Holidays have no history, so they can be deleted.
 */
final class HolidayService
{
    public function __construct(
        private readonly Holiday $holidays = new Holiday(),
        private readonly AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * @param array{holiday_date: string, name: string} $data
     */
    public function create(array $data): int
    {
        return Database::instance()->transaction(function () use ($data): int {
            $id = $this->holidays->insert($data);
            $this->audit->log('holiday.created', ['holiday_id' => $id] + $data);

            return $id;
        });
    }

    /**
     * @param array{holiday_date: string, name: string} $data
     */
    public function update(int $id, array $data): void
    {
        Database::instance()->transaction(function () use ($id, $data): void {
            $before = $this->holidays->find($id) ?? throw new ServiceException('Holiday not found.');
            $changes = AuditService::diff($before, $data);
            if ($changes === []) {
                return;
            }
            $this->holidays->update($id, $data);
            $this->audit->log('holiday.updated', ['holiday_id' => $id, 'changes' => $changes]);
        });
    }

    public function delete(int $id): void
    {
        Database::instance()->transaction(function () use ($id): void {
            $holiday = $this->holidays->find($id) ?? throw new ServiceException('Holiday not found.');
            $this->holidays->delete($id);
            $this->audit->log('holiday.deleted', [
                'holiday_id' => $id,
                'holiday_date' => $holiday['holiday_date'],
                'name' => $holiday['name'],
            ]);
        });
    }
}
