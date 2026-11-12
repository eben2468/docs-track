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
     * Holidays in a calendar year, by date.
     *
     * @return list<array<string, mixed>>
     */
    public function forYear(int $year): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date',
            [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]
        );
    }

    /**
     * Years that have at least one holiday, newest first.
     *
     * @return list<int>
     */
    public function years(): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll('SELECT DISTINCT YEAR(holiday_date) AS y FROM holidays ORDER BY y DESC'),
            'y'
        ));
    }

    /**
     * Remove a holiday. Returns affected rows.
     */
    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM holidays WHERE id = ?', [$id]);
    }
}
