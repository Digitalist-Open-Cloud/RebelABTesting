<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

/**
 * Compares the conversion rate of a variant with the one of the original.
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
