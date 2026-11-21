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
     * @dataProvider getSplits
     * @param int[] $visitors
     */
    public function testAnUnevenSplitIsUnlikely(array $visitors, float $chance): void
    {
        $this->assertEqualsWithDelta($chance, Statistics::chanceOfSplit($visitors), 0.0005);
    }

    public function getSplits(): array
    {
        // The chi-square values 3.841, 5.991, 7.815 and 9.488 are the 5% limits for 1 to 4 degrees of freedom.
        return [
            'even' => [[500, 500], 1.0],
            'two groups at the limit' => [[1000 + 44, 1000 - 44], 0.049],
            'three groups at the limit' => [[1000 + 55, 1000 - 55, 1000], 0.049],
            'four groups at the limit' => [[1000 + 63, 1000 - 63, 1000, 1000], 0.047],
            'five groups at the limit' => [[1000 + 69, 1000 - 69, 1000, 1000, 1000], 0.049],
            'clearly broken' => [[1000, 800], 0.0000024],
        ];
    }

    public function testNothingIsSaidAboutTheSplitOfFewVisitors(): void
    {
        $this->assertNull(Statistics::chanceOfSplit([29, 30]));
        $this->assertNull(Statistics::chanceOfSplit([1000]));
        $this->assertNull(Statistics::chanceOfSplit([]));
    }

    public function testTheRangeOfARateNarrowsWithMoreVisits(): void
    {
        // Wilson score interval of 10 out of 100.
        [$low, $high] = Statistics::rateInterval(100, 10);
        $this->assertEqualsWithDelta(0.0552, $low, 0.0005);
        $this->assertEqualsWithDelta(0.1744, $high, 0.0005);

        [$low, $high] = Statistics::rateInterval(10000, 1000);
        $this->assertEqualsWithDelta(0.0943, $low, 0.0005);
        $this->assertEqualsWithDelta(0.1060, $high, 0.0005);

        $this->assertSame([0.0, 0.0], Statistics::rateInterval(0, 0));
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
