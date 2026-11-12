<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WorkingDayService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WorkingDayServiceTest extends TestCase
{
    public function testAddsWeekdays(): void
    {
        // Monday 2026-03-02 + 3 working days = Thursday 2026-03-05
        $this->assertSame('2026-03-05', $this->add('2026-03-02', 3));
    }

    public function testSkipsWeekends(): void
    {
        // Friday + 1 = Monday; Thursday + 3 = Tuesday
        $this->assertSame('2026-03-09', $this->add('2026-03-06', 1));
        $this->assertSame('2026-03-10', $this->add('2026-03-05', 3));
    }

    public function testSkipsHolidays(): void
    {
        // Wednesday 2026-03-04 + 3, with Friday 6 March (Independence Day) off → Tuesday 10 March
        $this->assertSame('2026-03-10', $this->add('2026-03-04', 3, ['2026-03-06']));
    }

    public function testSkipsConsecutiveHolidaysAcrossAWeekend(): void
    {
        // Easter 2026: Good Friday 3 April, Easter Monday 6 April. Thursday 2 April + 1 → Tuesday 7 April.
        $this->assertSame('2026-04-07', $this->add('2026-04-02', 1, ['2026-04-03', '2026-04-06']));
    }

    public function testStartingOnAWeekendCountsFromTheNextWorkingDay(): void
    {
        // Saturday + 1 working day = Monday
        $this->assertSame('2026-03-09', $this->add('2026-03-07', 1));
    }

    public function testZeroDaysMovesToTheNextWorkingDay(): void
    {
        $this->assertSame('2026-03-04', $this->add('2026-03-04', 0));
        $this->assertSame('2026-03-09', $this->add('2026-03-07', 0));
    }

    public function testCrossesTheYearEnd(): void
    {
        // Thursday 24 Dec 2026 + 3, Christmas (Fri) off, Boxing Day falls on Saturday → Mon 28, Tue 29, Wed 30 Dec
        $this->assertSame('2026-12-30', $this->add('2026-12-24', 3, ['2026-12-25', '2026-12-26', '2027-01-01']));
        $this->assertSame('2027-01-04', $this->add('2026-12-31', 1, ['2027-01-01']));
    }

    public function testTimeOfDayIsIgnored(): void
    {
        $result = WorkingDayService::add(new DateTimeImmutable('2026-03-02 23:59:00'), 1, []);

        $this->assertSame('2026-03-03 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    /**
     * @param list<string> $holidays
     */
    private function add(string $from, int $days, array $holidays = []): string
    {
        return WorkingDayService::add(new DateTimeImmutable($from), $days, $holidays)->format('Y-m-d');
    }
}
