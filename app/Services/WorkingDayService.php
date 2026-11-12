<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Holiday;
use DateTimeImmutable;

/**
 * Working-day arithmetic for SLA due dates (FR-10): Saturdays, Sundays and dates in the
 * holidays table are not working days.
 */
final class WorkingDayService
{
    public function __construct(private readonly Holiday $holidays = new Holiday())
    {
    }

    /**
     * The date $days working days after $from (the start date itself is not counted).
     * With $days = 0 the result is $from, moved forward to a working day if needed.
     */
    public function addWorkingDays(DateTimeImmutable $from, int $days): DateTimeImmutable
    {
        $from = $from->setTime(0, 0);
        // Generous window: every 5 working days span 7 calendar days, plus room for holidays.
        $until = $from->modify('+' . (intdiv(max(0, $days), 5) * 7 + 7 + 60) . ' days');
        $holidays = $this->holidays->datesBetween($from->format('Y-m-d'), $until->format('Y-m-d'));

        return self::add($from, $days, $holidays);
    }

    /**
     * Pure calculation used by addWorkingDays().
     *
     * @param list<string> $holidays Dates (Y-m-d) that are not working days
     */
    public static function add(DateTimeImmutable $from, int $days, array $holidays): DateTimeImmutable
    {
        $off = array_fill_keys($holidays, true);
        $date = $from->setTime(0, 0);

        if ($days <= 0) {
            while (!self::isWorkingDay($date, $off)) {
                $date = $date->modify('+1 day');
            }

            return $date;
        }

        $counted = 0;
        while ($counted < $days) {
            $date = $date->modify('+1 day');
            if (self::isWorkingDay($date, $off)) {
                $counted++;
            }
        }

        return $date;
    }

    /**
     * @param array<string, true> $holidays
     */
    private static function isWorkingDay(DateTimeImmutable $date, array $holidays): bool
    {
        return (int) $date->format('N') < 6 && !isset($holidays[$date->format('Y-m-d')]);
    }
}
