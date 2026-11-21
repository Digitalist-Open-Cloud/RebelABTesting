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

    public const KIND_AB = 'ab';
    public const KIND_FAKE_DOOR = 'fake_door';
    public const KINDS = [self::KIND_AB, self::KIND_FAKE_DOOR];
    public const DOOR_POSITIONS = ['after', 'before', 'append', 'prepend'];
    public const DOOR_ELEMENTS = ['button', 'link'];

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
     * @param mixed $door what a request handed over
     * @return array{selector: string, position: string, element: string, label: string, css_class: string, message: string}
     */
    public static function normalizeDoor($door): array
    {
        $door = is_array($door) ? $door : [];
        $text = function (string $field) use ($door): string {
            return trim(is_scalar($door[$field] ?? '') ? (string) ($door[$field] ?? '') : '');
        };

        return [
            'selector' => $text('selector'),
            'position' => $text('position') ?: self::DOOR_POSITIONS[0],
            'element' => $text('element') ?: self::DOOR_ELEMENTS[0],
            'label' => $text('label'),
            'css_class' => $text('css_class'),
            'message' => $text('message'),
        ];
    }

    /**
     * @param array<string, string> $door
     */
    public static function validateDoor(array $door): ?string
    {
        if (
            $door['selector'] === '' || mb_strlen($door['selector']) > 500
            || $door['label'] === '' || mb_strlen($door['label']) > 200
            || $door['message'] === '' || mb_strlen($door['message']) > 500
            || !in_array($door['position'], self::DOOR_POSITIONS, true)
            || !in_array($door['element'], self::DOOR_ELEMENTS, true)
            || !preg_match('/^[A-Za-z0-9_ -]{0,200}$/', $door['css_class'])
        ) {
            return 'RebelABTesting_ErrorDoor';
        }

        return null;
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
