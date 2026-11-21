<?php

namespace Piwik\Plugins\RebelABTesting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\RebelABTesting\Validation\ExperimentValidator;

/**
 * @group RebelABTesting
 * @group Plugins
 */
final class ExperimentValidatorTest extends TestCase
{
    private const JANUARY = [['from_date' => '2026-01-01', 'to_date' => '2026-01-15']];

    /**
     * @dataProvider getNames
     */
    public function testName(string $name, ?string $error): void
    {
        $this->assertSame($error, ExperimentValidator::validateName($name));
    }

    public function getNames(): array
    {
        return [
            'ordinary' => ['spring-sale-v2', null],
            'as long as the column' => [str_repeat('a', 255), null],
            'empty' => ['', 'RebelABTesting_ErrorNameEmpty'],
            'only spaces' => ['   ', 'RebelABTesting_ErrorNameEmpty'],
            'too long' => [str_repeat('a', 256), 'RebelABTesting_ErrorNameTooLong'],
            'with a comma' => ['spring sale, v2', 'RebelABTesting_ErrorNameComma'],
        ];
    }

    /**
     * @dataProvider getDates
     */
    public function testDates(string $from, string $to, ?string $error): void
    {
        $this->assertSame($error, ExperimentValidator::validateDates($from, $to));
    }

    public function getDates(): array
    {
        return [
            'a month' => ['2026-01-01', '2026-01-31', null],
            'one day' => ['2026-01-01', '2026-01-01', null],
            'last day first' => ['2026-01-02', '2026-01-01', 'RebelABTesting_ErrorDateOrder'],
            'not a date' => ['soon', '2026-01-31', 'RebelABTesting_ErrorDateFormat'],
            'a day that does not exist' => ['2026-02-30', '2026-03-31', 'RebelABTesting_ErrorDateFormat'],
            'with a time' => ['2026-01-01 10:00', '2026-01-31', 'RebelABTesting_ErrorDateFormat'],
            'empty' => ['', '', 'RebelABTesting_ErrorDateFormat'],
        ];
    }

    /**
     * @dataProvider getPeriods
     */
    public function testOverlap(string $from, string $to, bool $overlaps): void
    {
        $this->assertSame($overlaps, ExperimentValidator::hasOverlap(self::JANUARY, $from, $to));
    }

    public function getPeriods(): array
    {
        return [
            'a later month' => ['2026-02-01', '2026-02-15', false],
            'the day after the last day' => ['2026-01-16', '2026-01-31', false],
            'starts on the last day' => ['2026-01-15', '2026-01-31', true],
            'starts during' => ['2026-01-10', '2026-01-20', true],
            'contains' => ['2025-12-01', '2026-02-20', true],
        ];
    }

    public function testNothingOverlapsWithoutOtherExperiments(): void
    {
        $this->assertFalse(ExperimentValidator::hasOverlap([], '2026-01-01', '2026-01-31'));
    }
}
