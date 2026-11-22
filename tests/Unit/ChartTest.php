<?php

namespace Piwik\Plugins\RebelExperiments\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\RebelExperiments\Chart;

/**
 * @group RebelExperiments
 * @group Plugins
 */
final class ChartTest extends TestCase
{
    public function testLinesRunFromLeftToRightBelowTheNextRoundNumber(): void
    {
        $chart = Chart::lines([
            ['name' => 'Original', 'values' => [0.0, 0.1, 0.2]],
            ['name' => 'Green', 'values' => [null, 0.4, 0.3]],
        ]);

        $this->assertSame([0.0, 0.125, 0.25, 0.375, 0.5], array_column($chart['grid'], 'value'));
        $this->assertSame([56.0, 333.0, 610.0], $chart['xs']);
        $this->assertSame('56,232 333,188 610,144', $chart['lines'][0]['points']);
        // A day without a value is left out of the line.
        $this->assertSame('333,56 610,100', $chart['lines'][1]['points']);
        $this->assertSame(['x' => 610.0, 'y' => 100.0, 'value' => 0.3], $chart['lines'][1]['last']);
        $this->assertNotSame($chart['lines'][0]['colour'], $chart['lines'][1]['colour']);
    }

    public function testLabelsOfLinesThatEndCloseToEachOtherAreMovedApart(): void
    {
        $chart = Chart::lines([
            ['name' => 'A', 'values' => [0.50]],
            ['name' => 'B', 'values' => [0.49]],
            ['name' => 'C', 'values' => [0.10]],
        ]);
        $labels = array_column($chart['lines'], 'label_y', 'name');

        $this->assertGreaterThanOrEqual(14, $labels['B'] - $labels['A']);
        $this->assertSame($chart['lines'][2]['last']['y'], $labels['C']);
    }

    public function testAChartHasEightLinesAtMostAndStandsWithoutValues(): void
    {
        $series = array_fill(0, 10, ['name' => 'Line', 'values' => [1.0, 2.0]]);

        $this->assertCount(8, Chart::lines($series)['lines']);
        $this->assertCount(8, array_unique(array_column(Chart::lines($series)['lines'], 'colour')));
        $this->assertSame([], Chart::lines([])['lines']);
        $this->assertNull(Chart::lines([['name' => 'Empty', 'values' => [null]]])['lines'][0]['last']);
    }
}
