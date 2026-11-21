<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Tracker;

/**
 * The experiment parameters of a tracking request. What the browser sends is not trusted: a value
 * that is not well-formed becomes null.
 */
class ExposureParams
{
    public const UNKNOWN_NAME = 'Unknown';

    private const NAME_MAX_LENGTH = 255;

    /**
     * @param array<string, mixed> $params
     * @return array{variant: ?int, idexperiment: ?int, experiment_name: string, assignment_id: ?string}|null
     *         null when the request is not about an experiment
     */
    public static function fromRequestParams(array $params): ?array
    {
        if (!isset($params['sabt']) || $params['sabt'] === '') {
            return null;
        }

        $variant = in_array((string) $params['sabt'], ['1', '2'], true) ? (int) $params['sabt'] : null;

        $id = $params['sabi'] ?? null;
        $idexperiment = (is_string($id) || is_int($id)) && preg_match('/^[1-9][0-9]{0,9}$/', (string) $id)
            ? (int) $id : null;

        $name = self::UNKNOWN_NAME;
        foreach (['sabn', 'experiment'] as $key) {
            if (isset($params[$key]) && is_string($params[$key]) && $params[$key] !== '') {
                $name = mb_substr($params[$key], 0, self::NAME_MAX_LENGTH);
                break;
            }
        }

        $assignment = $params['saba'] ?? null;
        $assignmentId = is_string($assignment) && preg_match('/^[0-9a-f]{32}$/', $assignment) ? $assignment : null;

        return [
            'variant' => $variant,
            'idexperiment' => $idexperiment,
            'experiment_name' => $name,
            'assignment_id' => $assignmentId,
        ];
    }

    /**
     * Whether the time is within the first and the last day of the experiment. Days are in UTC, as
     * in the tag.
     *
     * @param array<string, mixed>|null $experiment
     */
    public static function isWithinSchedule(int $timestamp, ?array $experiment): bool
    {
        if ($experiment === null) {
            return false;
        }
        $day = gmdate('Y-m-d', $timestamp);

        return $day >= (string) $experiment['from_date'] && $day <= (string) $experiment['to_date'];
    }
}
