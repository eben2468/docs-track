<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Public holidays excluded from working-day SLA calculations.
 * Unlike history tables, holidays may be deleted (admin CRUD, spec §7.4).
 */
final class Holiday extends Model
{
    protected string $table = 'holidays';

    /** @var list<string> */
    protected array $fillable = ['holiday_date', 'name'];

    /**
     * Holiday dates (Y-m-d) between two dates, inclusive.
     *
     * @return list<string>
     */
    public function datesBetween(string $from, string $to): array
    {
        return array_column(
            $this->db->fetchAll(
                'SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date',
                [$from, $to]
            ),
            'holiday_date'
        );
    }

    /**
     * Remove a holiday. Returns affected rows.
     */
    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM holidays WHERE id = ?', [$id]);
    }
}
