<?php

namespace Piwik\Plugins\RebelExperiments\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\RebelExperiments\Tracker\Assignments;

/**
 * @group RebelExperiments
 * @group Plugins
 */
final class AssignmentsTest extends TestCase
{
    private const A = '0123456789abcdef0123456789abcdef';

    public function testTheExperimentsOfARequestAreRead(): void
    {
        $this->assertSame(
            [7 => ['variant' => 2, 'assignment_id' => self::A], 12 => ['variant' => 1, 'assignment_id' => self::A]],
            Assignments::fromRequestParams(['rexp' => '7-2-' . self::A . ',12-1-' . self::A])
        );
    }

    /**
     * @dataProvider getValuesThatAreNotWellFormed
     * @param mixed $value
     */
    public function testWhatIsNotWellFormedIsLeftOut($value): void
    {
        $this->assertSame([], Assignments::fromRequestParams(['rexp' => $value]));
    }

    public function getValuesThatAreNotWellFormed(): array
    {
        return [
            'empty' => [''],
            'a list' => [['7-2-' . self::A]],
            'no variant' => ['7-0-' . self::A],
            'an ID that is not a number' => ["7' OR 1-2-" . self::A],
            'an assignment that is not hex' => ['7-2-' . str_repeat('z', 32)],
            'an assignment that is too short' => ['7-2-abc'],
            'a variant with three digits' => ['7-100-' . self::A],
        ];
    }

    public function testTheSignalOfAFakeDoorIsRead(): void
    {
        $this->assertSame(['idexperiment' => 7, 'signal' => 's'], Assignments::getDoorSignal(['rexpd' => '7-s']));
        $this->assertSame(['idexperiment' => 12, 'signal' => 'c'], Assignments::getDoorSignal(['rexpd' => '12-c']));

        foreach (['', '7', '7-x', '0-s', '7-s-c', ['7-s'], "7-s' OR 1"] as $value) {
            $this->assertNull(Assignments::getDoorSignal(['rexpd' => $value]));
        }
        $this->assertNull(Assignments::getDoorSignal([]));
    }

    public function testARequestWithoutTheParameterHasNoExperiments(): void
    {
        $this->assertSame([], Assignments::fromRequestParams(['idsite' => '1']));
    }

    public function testOnlyTheFirstTenExperimentsAreRead(): void
    {
        $entries = [];
        for ($id = 1; $id <= 12; $id++) {
            $entries[] = $id . '-1-' . self::A;
        }

        $this->assertSame(range(1, 10), array_keys(Assignments::fromRequestParams(['rexp' => implode(',', $entries)])));
    }
}
