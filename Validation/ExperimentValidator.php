<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelExperiments\Validation;

/**
 * The rules for an experiment. An error is the key of its translation.
 */
final class ExperimentValidator
{
    public const MATCH_ANY = 'any';
    public const MATCHES = [self::MATCH_ANY, 'equals', 'contains', 'starts_with', 'regexp'];
    public const MAX_VARIANTS = 10;
    public const EXCLUDE_NONE = 'none';
    public const EXCLUDES = [self::EXCLUDE_NONE, 'equals', 'contains', 'starts_with', 'regexp'];
    public const CONFIDENCES = [90, 95, 99];
    public const DECISIONS = ['', 'adopt', 'keep', 'inconclusive'];
    public const DECISION_NOTE_MAX_LENGTH = 2000;

    public const KIND_AB = 'ab';
    public const KIND_FAKE_DOOR = 'fake_door';
    public const KIND_WATCH = 'watch';
    public const KIND_ROLLOUT = 'rollout';
    public const KINDS = [self::KIND_AB, self::KIND_ROLLOUT, self::KIND_FAKE_DOOR, self::KIND_WATCH];
    public const DOOR_POSITIONS = ['after', 'before', 'append', 'prepend'];
    public const DOOR_ELEMENTS = ['button', 'link'];

    public const ACTION_HIDE = 'hide';
    public const ACTIONS = ['text', self::ACTION_HIDE, 'add_class', 'style', 'image', 'link', 'insert_before', 'insert_after', 'insert_first'];
    public const MAX_CHANGES = 20;

    private const NAME_MAX_LENGTH = 255;
    private const TARGET_MAX_LENGTH = 1000;
    private const SELECTOR_MAX_LENGTH = 500;
    private const VALUE_MAX_LENGTH = 2000;

    public static function validateName(string $name): ?string
    {
        if (trim($name) === '') {
            return 'RebelExperiments_ErrorNameEmpty';
        }

        return mb_strlen($name) > self::NAME_MAX_LENGTH ? 'RebelExperiments_ErrorNameTooLong' : null;
    }

    public static function validateDates(string $fromDate, string $toDate): ?string
    {
        foreach ([$fromDate, $toDate] as $date) {
            $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                return 'RebelExperiments_ErrorDateFormat';
            }
        }

        return $fromDate > $toDate ? 'RebelExperiments_ErrorDateOrder' : null;
    }

    public static function validateTrafficShare(int $percent): ?string
    {
        return $percent < 1 || $percent > 100 ? 'RebelExperiments_ErrorTrafficShare' : null;
    }

    public static function validateTarget(string $match, string $value): ?string
    {
        if (!in_array($match, self::MATCHES, true)) {
            return 'RebelExperiments_ErrorTargetMatch';
        }
        if ($match !== self::MATCH_ANY && ($value === '' || mb_strlen($value) > self::TARGET_MAX_LENGTH)) {
            return 'RebelExperiments_ErrorTargetValue';
        }

        return null;
    }

    /**
     * The pages an experiment leaves out, among the pages it runs on.
     */
    public static function validateExclude(string $match, string $value): ?string
    {
        if (!in_array($match, self::EXCLUDES, true)) {
            return 'RebelExperiments_ErrorTargetMatch';
        }
        if ($match !== self::EXCLUDE_NONE && ($value === '' || mb_strlen($value) > self::TARGET_MAX_LENGTH)) {
            return 'RebelExperiments_ErrorTargetValue';
        }

        return null;
    }

    public static function validateConfidence(int $percent): ?string
    {
        return in_array($percent, self::CONFIDENCES, true) ? null : 'RebelExperiments_ErrorConfidence';
    }

    /**
     * @param array<int, array<string, mixed>> $variants
     */
    public static function validateVariants(array $variants): ?string
    {
        if (count($variants) < 1 || count($variants) > self::MAX_VARIANTS) {
            return 'RebelExperiments_ErrorVariants';
        }

        foreach ($variants as $variant) {
            if (count($variant['changes']) > self::MAX_CHANGES) {
                return 'RebelExperiments_ErrorChange';
            }
            foreach ($variant['changes'] as $change) {
                if (!self::isValidChange($change)) {
                    return 'RebelExperiments_ErrorChange';
                }
            }
            if ($variant['redirect'] !== '' && (mb_strlen($variant['redirect']) > self::VALUE_MAX_LENGTH || !self::isSafeAddress($variant['redirect']))) {
                return 'RebelExperiments_ErrorRedirect';
            }
        }

        return null;
    }

    /**
     * Whether a variant has CSS or JavaScript, which only an admin of the website may write.
     *
     * @param array<int, array<string, mixed>> $variants
     */
    public static function hasCode(array $variants): bool
    {
        foreach ($variants as $variant) {
            if (trim(($variant['css'] ?? '') . ($variant['js'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * An address without a scheme stays on the website. Other schemes could run code.
     */
    private static function isSafeAddress(string $address): bool
    {
        return (bool) preg_match('~^(https?://|mailto:|tel:|(?![a-z][a-z0-9+.-]*:))~i', $address);
    }

    /**
     * @param array{selector: string, action: string, value: string} $change
     */
    private static function isValidChange(array $change): bool
    {
        if (
            !in_array($change['action'], self::ACTIONS, true)
            || mb_strlen($change['selector']) > self::SELECTOR_MAX_LENGTH
            || mb_strlen($change['value']) > self::VALUE_MAX_LENGTH
        ) {
            return false;
        }

        switch ($change['action']) {
            case self::ACTION_HIDE:
                return true;
            case 'add_class':
                return (bool) preg_match('/^[A-Za-z0-9_-]+( [A-Za-z0-9_-]+)*$/', $change['value']);
            case 'image':
            case 'link':
                return $change['value'] !== '' && self::isSafeAddress($change['value']);
            default:
                return $change['value'] !== '';
        }
    }

    /**
     * Whether the experiment only counts the visits its tag tells about: the ones a fake door was
     * shown to, or that saw a watched element. Such an experiment has no original and no variants.
     */
    public static function countsWhatIsShown(string $kind): bool
    {
        return in_array($kind, [self::KIND_FAKE_DOOR, self::KIND_WATCH], true);
    }

    /**
     * @param mixed $watch what a request handed over
     * @return array{selector: string}
     */
    public static function normalizeWatch($watch): array
    {
        return ['selector' => trim(is_array($watch) && is_scalar($watch['selector'] ?? null) ? (string) $watch['selector'] : '')];
    }

    /**
     * @param array{selector: string} $watch
     */
    public static function validateWatch(array $watch): ?string
    {
        return $watch['selector'] === '' || mb_strlen($watch['selector']) > self::SELECTOR_MAX_LENGTH ? 'RebelExperiments_ErrorWatch' : null;
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
            return 'RebelExperiments_ErrorDoor';
        }

        return null;
    }

    /**
     * @param mixed $variants what a request handed over
     * @return array<int, array{name: string, changes: array<int, array{selector: string, action: string, value: string}>, redirect: string, css: string, js: string}>
     */
    public static function normalizeVariants($variants): array
    {
        $normalized = [];
        foreach (is_array($variants) ? $variants : [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $changes = [];
            foreach (is_array($variant['changes'] ?? null) ? $variant['changes'] : [] as $change) {
                $selector = is_array($change) ? trim((string) ($change['selector'] ?? '')) : '';
                // A row of the form that was left empty.
                if ($selector === '') {
                    continue;
                }
                $action = (string) ($change['action'] ?? '');
                $changes[] = [
                    'selector' => $selector,
                    'action' => $action,
                    'value' => $action === self::ACTION_HIDE ? '' : trim((string) ($change['value'] ?? '')),
                ];
            }

            $normalized[] = [
                'name' => mb_substr(trim((string) ($variant['name'] ?? '')), 0, 100),
                'changes' => $changes,
                'redirect' => trim((string) ($variant['redirect'] ?? '')),
                'css' => (string) ($variant['css'] ?? ''),
                'js' => (string) ($variant['js'] ?? ''),
            ];
        }

        return $normalized;
    }
}
