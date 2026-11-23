<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelExperiments\Tracker;

/**
 * The experiments a tracking request says its visitor takes part in. What the browser sends is
 * not trusted: an entry that is not well-formed is left out.
 */
class Assignments
{
    public const PARAMETER = 'rexp';
    public const DOOR_PARAMETER = 'rexpd';
    public const DOOR_SHOWN = 's';
    public const DOOR_CLICKED = 'c';

    private const MAX_EXPERIMENTS = 10;

    /**
     * @param array<string, mixed> $params
     * @return array<int, array{variant: int, assignment_id: string}> by the ID of the experiment
     */
    public static function fromRequestParams(array $params): array
    {
        $value = $params[self::PARAMETER] ?? '';
        if (!is_string($value) || $value === '') {
            return [];
        }

        $assignments = [];
        foreach (array_slice(explode(',', $value), 0, self::MAX_EXPERIMENTS) as $entry) {
            if (preg_match('/^([1-9][0-9]{0,8})-([1-9][0-9]?)-([0-9a-f]{32})$/', $entry, $match)) {
                $assignments[(int) $match[1]] = ['variant' => (int) $match[2], 'assignment_id' => $match[3]];
            }
        }

        return $assignments;
    }

    /**
     * What the request tells about a fake door: that it was shown to the visitor, or clicked.
     *
     * @param array<string, mixed> $params
     * @return array{idexperiment: int, signal: string}|null
     */
    public static function getDoorSignal(array $params): ?array
    {
        $value = $params[self::DOOR_PARAMETER] ?? '';
        if (!is_string($value) || !preg_match('/^([1-9][0-9]{0,8})-([' . self::DOOR_SHOWN . self::DOOR_CLICKED . '])$/', $value, $match)) {
            return null;
        }

        return ['idexperiment' => (int) $match[1], 'signal' => $match[2]];
    }
}
