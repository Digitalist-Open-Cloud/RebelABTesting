<?php

namespace Piwik\Plugins\RebelABTesting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\RebelABTesting\Statistics;

/**
 * @group RebelABTesting
 * @group Plugins
 */
final class StatisticsTest extends TestCase
{
    public function testEqualRatesGiveAnEvenChance(): void
    {
        $this->assertEqualsWithDelta(0.5, Statistics::chanceToBeat(1000, 100, 1000, 100), 0.0001);
    }

    public function testAClearlyBetterVariantIsAlmostCertain(): void
    {
        // z = 0.05 / sqrt(0.1 * 0.9 / 1000 + 0.15 * 0.85 / 1000) = 3.39
        $this->assertEqualsWithDelta(0.99965, Statistics::chanceToBeat(1000, 100, 1000, 150), 0.0001);
    }

    public function testAWorseVariantMirrorsABetterOne(): void
    {
        $this->assertEqualsWithDelta(1 - 0.99965, Statistics::chanceToBeat(1000, 150, 1000, 100), 0.0001);
    }

    public function testASmallDifferenceIsNotCertain(): void
    {
        // z = 0.01 / sqrt(0.1 * 0.9 / 1000 + 0.11 * 0.89 / 1000) = 0.73
        $this->assertEqualsWithDelta(0.767, Statistics::chanceToBeat(1000, 100, 1000, 110), 0.001);
    }

    /**
     * @dataProvider getTooLittleData
     */
    public function testNothingIsSaidWithTooLittleData(int $originalVisits, int $originalConverted, int $variantVisits, int $variantConverted): void
    {
        $this->assertNull(Statistics::chanceToBeat($originalVisits, $originalConverted, $variantVisits, $variantConverted));
    }

    public function getTooLittleData(): array
    {
        return [
            'no visits' => [0, 0, 0, 0],
            'few visits' => [29, 10, 1000, 100],
            'few conversions of the original' => [1000, 4, 1000, 100],
            'few conversions of the variant' => [1000, 100, 1000, 4],
            'nearly all visits converted' => [1000, 100, 1000, 996],
        ];
    }
}
