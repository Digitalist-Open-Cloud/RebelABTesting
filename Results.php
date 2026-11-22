<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Piwik\Common;
use Piwik\Db;
use Piwik\Piwik;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;
use Piwik\Plugins\RebelABTesting\Validation\ExperimentValidator;

/**
 * How the variants of an experiment did, over all the time it ran.
 */
class Results
{
    public const VERDICT_NOT_ENOUGH_DATA = 'not_enough_data';
    public const VERDICT_BETTER = 'better';
    public const VERDICT_WORSE = 'worse';
    public const VERDICT_NO_DIFFERENCE = 'no_difference';

    private const CERTAIN = 0.95;
    private const UNLIKELY_SPLIT = 0.001;
    public const ROLE_PRIMARY = 'primary';
    public const ROLE_GUARDRAIL = 'guardrail';
    public const ROLE_OTHER = 'other';

    /**
     * @param array<string, mixed> $experiment
     * @return array<string, mixed>
     */
    public function get(array $experiment): array
    {
        $bind = [$experiment['id'], $experiment['idsite']];
        $log = Common::prefixTable(LogExperiment::TABLE);

        if (ExperimentValidator::countsWhatIsShown($experiment['kind'])) {
            $door = Db::fetchRow("SELECT COUNT(*) AS nb_visits, COALESCE(SUM(clicked), 0) AS nb_clicks FROM $log WHERE idexperiment = ? AND idsite = ?", $bind);
            $visits = (int) $door['nb_visits'];
            $clicks = (int) $door['nb_clicks'];

            return [
                'nb_visits' => $visits,
                'nb_clicks' => $clicks,
                'click_rate' => $visits > 0 ? $clicks / $visits : 0.0,
                'click_rate_range' => Statistics::rateInterval($visits, $clicks),
            ];
        }

        // ponytail: reads the raw log, so visits whose log Matomo has deleted are gone from the result;
        // archive per experiment if tests run longer than the logs are kept
        $perVariant = array_column(
            Db::fetchAll("SELECT variant, COUNT(*) AS nb_visits, COUNT(DISTINCT assignment_id) AS nb_visitors FROM $log WHERE idexperiment = ? AND idsite = ? GROUP BY variant", $bind),
            null,
            'variant'
        );
        $variants = [];
        foreach ($this->getVariantNames($experiment) as $number => $name) {
            $variants[$number] = [
                'variant' => $number,
                'name' => $name,
                'nb_visits' => (int) ($perVariant[$number]['nb_visits'] ?? 0),
                'nb_visitors' => (int) ($perVariant[$number]['nb_visitors'] ?? 0),
            ];
        }

        $idSite = (int) $experiment['idsite'];
        $measures = [];
        $add = function (array $measure, string $role) use (&$measures, $experiment, $variants, $idSite) {
            $key = Measures::getKey($measure);
            if (isset($measures[$key])) {
                return;
            }
            $counts = (new Measures())->count($experiment, $measure);
            if ($role === self::ROLE_OTHER && empty($counts)) {
                return;
            }
            $measures[$key] = [
                'name' => Measures::getName($idSite, $measure),
                'role' => $role,
                'is_amount' => Measures::isAmount($measure),
                'is_lower_better' => Measures::isLowerBetter($measure),
                'variants' => $this->compare($variants, $counts, $measure),
            ];
        };

        if ($experiment['primary_measure'] !== null) {
            $add($experiment['primary_measure'], self::ROLE_PRIMARY);
        }
        foreach ($experiment['guardrails'] as $guardrail) {
            $add($guardrail, self::ROLE_GUARDRAIL);
        }
        // The goals of the website are shown as well, when a visit of the experiment reached them.
        foreach (GoalsApi::getInstance()->getGoals($idSite) as $goal) {
            $add(['type' => Measures::GOAL, 'idgoal' => (int) $goal['idgoal']], self::ROLE_OTHER);
        }

        // Visitors are what is split, a visitor can come back for several visits.
        $chanceOfSplit = Statistics::chanceOfSplit(array_column($variants, 'nb_visitors'));

        return [
            'variants' => array_values($variants),
            'is_split_even' => $chanceOfSplit === null ? null : $chanceOfSplit >= self::UNLIKELY_SPLIT,
            'measures' => array_values($measures),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $variants
     * @param array<int, array{count: int, sum: float, sum_of_squares: float}> $counts
     * @param array<string, mixed> $measure
     * @return array<int, array<string, mixed>>
     */
    private function compare(array $variants, array $counts, array $measure): array
    {
        $isAmount = Measures::isAmount($measure);
        $none = ['count' => 0, 'sum' => 0.0, 'sum_of_squares' => 0.0];
        $original = null;
        $result = [];

        foreach ($variants as $number => $variant) {
            $count = ($counts[$number] ?? $none) + ['nb_visits' => $variant['nb_visits']];
            $line = $variant + [
                'nb_visits_with_outcome' => $count['count'],
                'value' => $variant['nb_visits'] > 0 ? ($isAmount ? $count['sum'] : $count['count']) / $variant['nb_visits'] : 0.0,
                'uplift' => null,
                'chance_to_be_better' => null,
                'verdict' => null,
            ];

            if ($original === null) {
                $original = $count + ['value' => $line['value']];
            } else {
                $chance = $isAmount
                    ? Statistics::chanceOfHigherAverage($original['nb_visits'], $original['sum'], $original['sum_of_squares'], $count['nb_visits'], $count['sum'], $count['sum_of_squares'])
                    : Statistics::chanceToBeat($original['nb_visits'], $original['count'], $count['nb_visits'], $count['count']);
                if ($chance !== null && Measures::isLowerBetter($measure)) {
                    $chance = 1 - $chance;
                }

                $line['uplift'] = $original['value'] > 0 ? $line['value'] / $original['value'] - 1 : null;
                $line['chance_to_be_better'] = $chance;
                $line['verdict'] = self::getVerdict($chance);
            }

            $result[] = $line;
        }

        return $result;
    }

    private static function getVerdict(?float $chance): string
    {
        if ($chance === null) {
            return self::VERDICT_NOT_ENOUGH_DATA;
        }
        if ($chance >= self::CERTAIN) {
            return self::VERDICT_BETTER;
        }

        return $chance <= 1 - self::CERTAIN ? self::VERDICT_WORSE : self::VERDICT_NO_DIFFERENCE;
    }

    /**
     * @param array<string, mixed> $experiment
     * @return array<int, string> by the number of the variant, 1 being the original
     */
    private function getVariantNames(array $experiment): array
    {
        $names = [1 => Piwik::translate('RebelABTesting_Original')];
        foreach ($experiment['variants'] as $index => $variant) {
            $names[$index + 2] = $variant['name'] !== '' ? $variant['name'] : Piwik::translate('RebelABTesting_VariantNumber', $index + 1);
        }

        return $names;
    }
}
