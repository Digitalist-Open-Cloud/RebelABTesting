<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Validation;

/**
 * The rules for the name and the schedule of an experiment. An error is the key of its translation.
 */
final class ExperimentValidator
{
    private const NAME_MAX_LENGTH = 255;

    public static function validateName(string $name): ?string
    {
        if (trim($name) === '') {
            return 'RebelABTesting_ErrorNameEmpty';
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return 'RebelABTesting_ErrorNameTooLong';
        }
        // Tags published by older versions keep the experiment as a comma-separated text.
        if (strpos($name, ',') !== false) {
            return 'RebelABTesting_ErrorNameComma';
        }

        return null;
    }

    public static function validateDates(string $fromDate, string $toDate): ?string
    {
        foreach ([$fromDate, $toDate] as $date) {
            $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                return 'RebelABTesting_ErrorDateFormat';
            }
        }

        return $fromDate > $toDate ? 'RebelABTesting_ErrorDateOrder' : null;
    }

    /**
     * Whether the period shares a day with one of the existing periods.
     *
     * @param array<int, array{from_date: string, to_date: string}> $existingRanges
     */
    public static function hasOverlap(array $existingRanges, string $newFrom, string $newTo): bool
    {
        foreach ($existingRanges as $range) {
            if ($newFrom <= $range['to_date'] && $newTo >= $range['from_date']) {
                return true;
            }
        }

        return false;
    }
}
