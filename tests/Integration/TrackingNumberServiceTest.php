<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\TrackingNumberService;
use LogicException;
use Tests\DatabaseTestCase;

final class TrackingNumberServiceTest extends DatabaseTestCase
{
    public function testFormatPadsSequenceToFiveDigits(): void
    {
        $this->assertSame('VVU-REG-2026-00042', TrackingNumberService::format('reg', 2026, 42));
        $this->assertSame('VVU-BUR-2026-00007', TrackingNumberService::format('BUR', 2026, 7));
        $this->assertSame('VVU-ICT-2026-123456', TrackingNumberService::format('ICT', 2026, 123456));
    }

    public function testIsValidRecognisesTrackingNumbers(): void
    {
        $this->assertTrue(TrackingNumberService::isValid('VVU-REG-2026-00042'));
        $this->assertTrue(TrackingNumberService::isValid(' vvu-fob-2027-00001 '));
        $this->assertFalse(TrackingNumberService::isValid('VVU-REG-26-00042'));
        $this->assertFalse(TrackingNumberService::isValid('ABC-REG-2026-00042'));
        $this->assertFalse(TrackingNumberService::isValid('VVU-R-2026-00042'));
    }

    public function testFirstNumberForDepartmentAndYearIsOne(): void
    {
        $reg = $this->createDepartment('REG');

        $this->assertSame('VVU-REG-2026-00001', (new TrackingNumberService($this->db))->next($reg, 2026));
    }

    public function testNumbersIncreaseSequentiallyPerDepartment(): void
    {
        $reg = $this->createDepartment('REG');
        $bur = $this->createDepartment('BUR');
        $service = new TrackingNumberService($this->db);

        $this->assertSame('VVU-REG-2026-00001', $service->next($reg, 2026));
        $this->assertSame('VVU-REG-2026-00002', $service->next($reg, 2026));
        $this->assertSame('VVU-BUR-2026-00001', $service->next($bur, 2026));
        $this->assertSame('VVU-REG-2026-00003', $service->next($reg, 2026));
        $this->assertSame('VVU-BUR-2026-00002', $service->next($bur, 2026));
    }

    public function testSequenceResetsEachYear(): void
    {
        $reg = $this->createDepartment('REG');
        $service = new TrackingNumberService($this->db);

        $service->next($reg, 2026);
        $service->next($reg, 2026);

        $this->assertSame('VVU-REG-2027-00001', $service->next($reg, 2027));
        $this->assertSame('VVU-REG-2026-00003', $service->next($reg, 2026), 'The previous year keeps its own counter.');
    }

    public function testDefaultsToCurrentYear(): void
    {
        $reg = $this->createDepartment('REG');

        $this->assertSame(
            sprintf('VVU-REG-%s-00001', now()->format('Y')),
            (new TrackingNumberService($this->db))->next($reg)
        );
    }

    public function testCounterIsStoredInTrackingSequences(): void
    {
        $ict = $this->createDepartment('ICT');
        $service = new TrackingNumberService($this->db);
        $service->next($ict, 2026);
        $service->next($ict, 2026);

        $this->assertSame(2, (int) $this->db->fetchValue(
            'SELECT last_number FROM tracking_sequences WHERE department_id = ? AND year = 2026',
            [$ict]
        ));
    }

    public function testRefusesToRunOutsideATransaction(): void
    {
        $reg = $this->createDepartment('REG');
        $this->db->pdo()->commit();

        try {
            $this->expectException(LogicException::class);
            (new TrackingNumberService($this->db))->next($reg, 2026);
        } finally {
            // Clean up the committed department so other tests start empty.
            $this->db->execute('DELETE FROM departments WHERE id = ?', [$reg]);
        }
    }

    public function testUnknownDepartmentIsRejected(): void
    {
        $this->expectException(LogicException::class);
        (new TrackingNumberService($this->db))->next(999999, 2026);
    }
}
