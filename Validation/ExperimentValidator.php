<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Validation;

/**
 * The rules for an experiment. An error is the key of its translation.
 */
final class ExperimentValidator
{
    public const MATCH_ANY = 'any';
    public const MATCHES = [self::MATCH_ANY, 'equals', 'contains', 'starts_with', 'regexp'];
    public const MAX_VARIANTS = 10;

    private const NAME_MAX_LENGTH = 255;
    private const TARGET_MAX_LENGTH = 1000;

    public static function validateName(string $name): ?string
    {
        if (trim($name) === '') {
            return 'RebelABTesting_ErrorNameEmpty';
        }

        return mb_strlen($name) > self::NAME_MAX_LENGTH ? 'RebelABTesting_ErrorNameTooLong' : null;
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

    public static function validateTrafficShare(int $percent): ?string
    {
        return $percent < 1 || $percent > 100 ? 'RebelABTesting_ErrorTrafficShare' : null;
    }

    public static function validateTarget(string $match, string $value): ?string
    {
        if (!in_array($match, self::MATCHES, true)) {
            return 'RebelABTesting_ErrorTargetMatch';
        }
        if ($match !== self::MATCH_ANY && ($value === '' || mb_strlen($value) > self::TARGET_MAX_LENGTH)) {
            return 'RebelABTesting_ErrorTargetValue';
        }

        return null;
    }

    /**
     * @param array<int, array{name: string, css: string, js: string}> $variants
     */
    public static function validateVariants(array $variants): ?string
    {
        return count($variants) < 1 || count($variants) > self::MAX_VARIANTS ? 'RebelABTesting_ErrorVariants' : null;
    }

    /**
     * @param mixed $variants what a request handed over
     * @return array<int, array{name: string, css: string, js: string}>
     */
    public static function normalizeVariants($variants): array
    {
        $normalized = [];
        foreach (is_array($variants) ? $variants : [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $normalized[] = [
                'name' => mb_substr(trim((string) ($variant['name'] ?? '')), 0, 100),
                'css' => (string) ($variant['css'] ?? ''),
                'js' => (string) ($variant['js'] ?? ''),
            ];
        }

        return $normalized;
    }
}
