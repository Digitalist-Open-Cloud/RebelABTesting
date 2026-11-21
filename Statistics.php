<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

/**
 * The statistics behind the result of an experiment.
 */
final class Statistics
{
    private const MIN_VISITS = 30;
    private const MIN_OF_EACH_OUTCOME = 5;

    /**
     * How likely it is that the variant converts better than the original, from 0 to 1. Null when
     * there are too few visits or conversions to tell.
     *
     * A two-proportion z-test, which is a fair approximation from a few conversions on.
     */
    public static function chanceToBeat(int $originalVisits, int $originalConverted, int $variantVisits, int $variantConverted): ?float
    {
        foreach ([[$originalVisits, $originalConverted], [$variantVisits, $variantConverted]] as [$visits, $converted]) {
            if ($visits < self::MIN_VISITS || $converted < self::MIN_OF_EACH_OUTCOME || $visits - $converted < self::MIN_OF_EACH_OUTCOME) {
                return null;
            }
        }

        $original = $originalConverted / $originalVisits;
        $variant = $variantConverted / $variantVisits;
        $error = sqrt($original * (1 - $original) / $originalVisits + $variant * (1 - $variant) / $variantVisits);

        return self::normalDistribution(($variant - $original) / $error);
    }

    /**
     * How likely it is that visits of the variant have a higher amount on average than visits of the
     * original, from 0 to 1. Null when there are too few visits to tell, or no amounts at all.
     *
     * @param float $originalSquares the squares of the amounts of the original's visits, added up
     */
    public static function chanceOfHigherAverage(int $originalVisits, float $originalSum, float $originalSquares, int $variantVisits, float $variantSum, float $variantSquares): ?float
    {
        if ($originalVisits < self::MIN_VISITS || $variantVisits < self::MIN_VISITS) {
            return null;
        }

        $original = $originalSum / $originalVisits;
        $variant = $variantSum / $variantVisits;
        $error = sqrt(
            max(0, $originalSquares / $originalVisits - $original ** 2) / ($originalVisits - 1)
            + max(0, $variantSquares / $variantVisits - $variant ** 2) / ($variantVisits - 1)
        );

        return $error > 0 ? self::normalDistribution(($variant - $original) / $error) : null;
    }

    /**
     * How likely a split at least this uneven is when every group has the same chance to get a
     * visitor, from 0 to 1. A very small number means that visitors are not assigned or counted
     * as they should be. Null when there are too few visitors to tell.
     *
     * @param int[] $visitorsPerGroup
     */
    public static function chanceOfSplit(array $visitorsPerGroup): ?float
    {
        $groups = count($visitorsPerGroup);
        $expected = $groups > 0 ? array_sum($visitorsPerGroup) / $groups : 0;
        if ($groups < 2 || $expected < self::MIN_VISITS) {
            return null;
        }

        $chiSquare = 0;
        foreach ($visitorsPerGroup as $visitors) {
            $chiSquare += ($visitors - $expected) ** 2 / $expected;
        }

        return self::chiSquareTail($chiSquare, $groups - 1);
    }

    /**
     * The range the true rate lies in with 95% confidence (Wilson score interval).
     *
     * @return array{0: float, 1: float}
     */
    public static function rateInterval(int $visits, int $successes): array
    {
        if ($visits === 0) {
            return [0.0, 0.0];
        }

        $z = 1.959964;
        $rate = $successes / $visits;
        $centre = $rate + $z * $z / (2 * $visits);
        $margin = $z * sqrt($rate * (1 - $rate) / $visits + $z * $z / (4 * $visits * $visits));
        $divisor = 1 + $z * $z / $visits;

        return [max(0.0, ($centre - $margin) / $divisor), min(1.0, ($centre + $margin) / $divisor)];
    }

    /**
     * The share of a chi-square distribution above $x.
     */
    private static function chiSquareTail(float $x, int $degreesOfFreedom): float
    {
        $half = $x / 2;

        if ($degreesOfFreedom % 2 === 0) {
            $term = exp(-$half);
            $sum = 0;
            for ($i = 1; $i <= $degreesOfFreedom / 2; $i++) {
                $sum += $term;
                $term *= $half / $i;
            }

            return min(1.0, $sum);
        }

        $sum = 2 * (1 - self::normalDistribution(sqrt($x)));
        $term = sqrt(2 * $x / M_PI) * exp(-$half);
        for ($i = 1; $i <= ($degreesOfFreedom - 1) / 2; $i++) {
            $sum += $term;
            $term *= $half / ($i + 0.5);
        }

        return min(1.0, $sum);
    }

    /**
     * The share of a normal distribution below $z.
     */
    private static function normalDistribution(float $z): float
    {
        // Abramowitz and Stegun 7.1.26, off by less than 0.0000002.
        $x = abs($z) / M_SQRT2;
        $t = 1 / (1 + 0.3275911 * $x);
        $erf = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return 0.5 * (1 + ($z < 0 ? -$erf : $erf));
    }
}
