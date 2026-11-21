<?php

namespace Piwik\Plugins\RebelABTesting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\RebelABTesting\Tracker\ExposureParams;

/**
 * @group RebelABTesting
 * @group Plugins
 */
final class ExposureParamsTest extends TestCase
{
    public function testFullTagRequest(): void
    {
        $r = ExposureParams::fromRequestParams([
            'sabt' => '2', 'sabi' => '7', 'sabn' => 'Knapp grön', 'saba' => str_repeat('a', 32),
        ]);
        $this->assertSame(2, $r['variant']);
        $this->assertSame(7, $r['idexperiment']);
        $this->assertSame('Knapp grön', $r['experiment_name']);
        $this->assertSame(str_repeat('a', 32), $r['assignment_id']);
    }

    public function testLegacyTrackerRequestFallsBackToExperimentName(): void
    {
        $r = ExposureParams::fromRequestParams(['sabt' => '1', 'experiment' => 'test']);
        $this->assertSame(1, $r['variant']);
        $this->assertNull($r['idexperiment']);
        $this->assertSame('test', $r['experiment_name']);
        $this->assertNull($r['assignment_id']);
    }

    public function testTamperedValuesBecomeNull(): void
    {
        $r = ExposureParams::fromRequestParams(['sabt' => '3', 'sabi' => '7; DROP', 'saba' => 'ZZ']);
        $this->assertNull($r['variant']);
        $this->assertNull($r['idexperiment']);
        $this->assertNull($r['assignment_id']);
        $this->assertSame(ExposureParams::UNKNOWN_NAME, $r['experiment_name']);
    }

    public function testALongNameIsCutToWhatTheColumnHolds(): void
    {
        $r = ExposureParams::fromRequestParams(['sabt' => '1', 'sabn' => str_repeat('ä', 300)]);
        $this->assertSame(255, mb_strlen($r['experiment_name']));
    }

    public function testNoSabtMeansNoRow(): void
    {
        $this->assertNull(ExposureParams::fromRequestParams([]));
    }

    public function testScheduleIncludesBothEndDaysInUtc(): void
    {
        $row = ['from_date' => '2026-10-10', 'to_date' => '2026-10-16'];
        $this->assertTrue(ExposureParams::isWithinSchedule(gmmktime(0, 0, 0, 10, 10, 2026), $row));
        $this->assertTrue(ExposureParams::isWithinSchedule(gmmktime(23, 59, 59, 10, 16, 2026), $row));
        $this->assertTrue(ExposureParams::isWithinSchedule(gmmktime(12, 0, 0, 10, 13, 2026), $row));
    }

    public function testScheduleExcludesRequestsAfterConcludeOrBeforeStart(): void
    {
        $row = ['from_date' => '2026-10-10', 'to_date' => '2026-10-16'];
        $this->assertFalse(ExposureParams::isWithinSchedule(gmmktime(0, 0, 0, 10, 17, 2026), $row));
        $this->assertFalse(ExposureParams::isWithinSchedule(gmmktime(23, 59, 59, 10, 9, 2026), $row));
    }

    public function testScheduleWithoutRowIsOutside(): void
    {
        $this->assertFalse(ExposureParams::isWithinSchedule(gmmktime(12, 0, 0, 10, 13, 2026), null));
    }
}
