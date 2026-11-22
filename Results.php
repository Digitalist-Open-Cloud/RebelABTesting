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
use Piwik\Metrics\Formatter;
use Piwik\NumberFormatter;
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

    private const UNLIKELY_SPLIT = 0.001;
    public const ROLE_PRIMARY = 'primary';
    public const ROLE_GUARDRAIL = 'guardrail';
    public const ROLE_OTHER = 'other';

    /**
     * @param array<string, mixed> $experiment
     * @param string $segment limits the result to the visits of a segment
     * @param bool $withGoals whether to add the goals of the website to the primary measure and the
     *                        guardrails
     * @return array<string, mixed>
     */
    public function get(array $experiment, string $segment = '', bool $withGoals = true): array
    {
        $filter = Measures::getSegmentFilter($experiment, $segment);
        $bind = array_merge([$experiment['id'], $experiment['idsite']], $filter[1]);
        $where = 'l.idexperiment = ? AND l.idsite = ?' . $filter[0];
        $log = Common::prefixTable(LogExperiment::TABLE);

        if (ExperimentValidator::countsWhatIsShown($experiment['kind'])) {
            $door = Db::fetchRow("SELECT COUNT(*) AS nb_visits, COALESCE(SUM(l.clicked), 0) AS nb_clicks FROM $log l WHERE $where", $bind);
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
            Db::fetchAll("SELECT l.variant, COUNT(*) AS nb_visits, COUNT(DISTINCT l.assignment_id) AS nb_visitors FROM $log l WHERE $where GROUP BY l.variant", $bind),
            null,
            'variant'
        );
        $variants = [];
        foreach ($this->getVariantNames($experiment) as $number => $name) {
            $variants[$number] = [
                'idsite' => (int) $experiment['idsite'],
                'variant' => $number,
                'name' => $name,
                'nb_visits' => (int) ($perVariant[$number]['nb_visits'] ?? 0),
                'nb_visitors' => (int) ($perVariant[$number]['nb_visitors'] ?? 0),
            ];
        }

        $idSite = (int) $experiment['idsite'];
        $measures = [];
        $certain = $experiment['confidence'] / 100;
        $add = function (array $measure, string $role) use (&$measures, $experiment, $variants, $idSite, $filter, $certain) {
            $key = Measures::getKey($measure);
            if (isset($measures[$key])) {
                return;
            }
            $counts = array_column((new Measures())->count($experiment, $measure, $filter), null, 'variant');
            if ($role === self::ROLE_OTHER && empty($counts)) {
                return;
            }
            $measures[$key] = [
                'name' => Measures::getName($idSite, $measure),
                'role' => $role,
                'unit' => Measures::getUnit($measure),
                'is_lower_better' => Measures::isLowerBetter($measure),
                'variants' => $this->compare($variants, $counts, $measure, $certain),
            ];
        };

        if ($experiment['primary_measure'] !== null) {
            $add($experiment['primary_measure'], self::ROLE_PRIMARY);
        }
        foreach ($experiment['guardrails'] as $guardrail) {
            $add($guardrail, self::ROLE_GUARDRAIL);
        }
        // The goals of the website are shown as well, when a visit of the experiment reached them.
        foreach ($withGoals ? GoalsApi::getInstance()->getGoals($idSite) : [] as $goal) {
            $add(['type' => Measures::GOAL, 'idgoal' => (int) $goal['idgoal']], self::ROLE_OTHER);
        }

        // Visitors are what is split, a visitor can come back for several visits.
        $chanceOfSplit = Statistics::chanceOfSplit(array_column($variants, 'nb_visitors'));

        foreach ($variants as &$variant) {
            unset($variant['idsite']);
        }

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
    private function compare(array $variants, array $counts, array $measure, float $certain): array
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
                'value_formatted' => '',
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
                $line['verdict'] = self::getVerdict($chance, $certain);
            }

            $line['value_formatted'] = self::format(Measures::getUnit($measure), $line['value'], (int) $variant['idsite']);
            unset($line['idsite']);
            $result[] = $line;
        }

        return $result;
    }

    /**
     * @param string $unit a unit of Measures
     * @return string the value as a visitor of Matomo reads it
     */
    public static function format(string $unit, float $value, int $idSite): string
    {
        $formatter = new Formatter();

        switch ($unit) {
            case Measures::UNIT_SHARE:
                return NumberFormatter::getInstance()->formatPercent($value * 100, 2);
            case Measures::UNIT_MONEY:
                return $formatter->getPrettyMoney((string) round($value, 2), $idSite);
            case Measures::UNIT_SECONDS:
                return $formatter->getPrettyTimeFromSeconds((int) round($value), true);
            default:
                return $formatter->getPrettyNumber($value, 2);
        }
    }

    private static function getVerdict(?float $chance, float $certain): string
    {
        if ($chance === null) {
            return self::VERDICT_NOT_ENOUGH_DATA;
        }
        if ($chance >= $certain) {
            return self::VERDICT_BETTER;
        }

        return $chance <= 1 - $certain ? self::VERDICT_WORSE : self::VERDICT_NO_DIFFERENCE;
    }

    /**
     * The result of an experiment in one line: what its primary measure says, or for a fake door or
     * a watched element the share that clicked.
     *
     * @param array<string, mixed> $experiment
     * @return array{verdict: string, text: string}|null null when the experiment has no primary
     *         measure or no visits yet
     */
    public function getSummary(array $experiment): ?array
    {
        $result = $this->get($experiment, '', false);

        if (ExperimentValidator::countsWhatIsShown($experiment['kind'])) {
            return $result['nb_visits'] === 0 ? null : [
                'verdict' => self::VERDICT_NO_DIFFERENCE,
                'text' => Piwik::translate('RebelABTesting_SummaryClicked', self::format(Measures::UNIT_SHARE, $result['click_rate'], (int) $experiment['idsite'])),
            ];
        }
        if (empty($result['measures']) || array_sum(array_column($result['variants'], 'nb_visits')) === 0) {
            return null;
        }

        $variants = array_slice($result['measures'][0]['variants'], 1);
        $verdicts = array_column($variants, 'verdict');
        $better = array_filter($variants, function (array $variant): bool {
            return $variant['verdict'] === self::VERDICT_BETTER;
        });

        if (!empty($better)) {
            usort($better, function (array $a, array $b): int {
                return $b['chance_to_be_better'] <=> $a['chance_to_be_better'];
            });
            $uplift = $better[0]['uplift'] === null ? '' : ' (' . ($better[0]['uplift'] > 0 ? '+' : '') . self::format(Measures::UNIT_SHARE, $better[0]['uplift'], (int) $experiment['idsite']) . ')';

            return ['verdict' => self::VERDICT_BETTER, 'text' => Piwik::translate('RebelABTesting_SummaryBetter', $better[0]['name']) . $uplift];
        }
        if (array_unique($verdicts) === [self::VERDICT_WORSE]) {
            return ['verdict' => self::VERDICT_WORSE, 'text' => Piwik::translate('RebelABTesting_SummaryWorse')];
        }

        $verdict = in_array(self::VERDICT_NOT_ENOUGH_DATA, $verdicts, true) ? self::VERDICT_NOT_ENOUGH_DATA : self::VERDICT_NO_DIFFERENCE;

        return ['verdict' => $verdict, 'text' => Piwik::translate('RebelABTesting_Verdict_' . $verdict)];
    }

    /**
     * How the primary measure of each variant developed, day by day, counted from the first day of
     * the experiment. Without a primary measure: the visits of each variant.
     *
     * @param array<string, mixed> $experiment
     * @return array{name: string, unit: string, days: string[], series: array<int, array{variant: int, name: string, values: array<int, ?float>}>}|null
     *         null when there is nothing to show yet
     */
    public function getEvolution(array $experiment, string $segment = ''): ?array
    {
        if (ExperimentValidator::countsWhatIsShown($experiment['kind'])) {
            return null;
        }

        $filter = Measures::getSegmentFilter($experiment, $segment);
        $visits = Db::fetchAll(
            'SELECT l.variant, DATE(l.server_time) AS day, COUNT(*) AS nb FROM ' . Common::prefixTable(LogExperiment::TABLE) . ' l
              WHERE l.idexperiment = ? AND l.idsite = ?' . $filter[0] . ' GROUP BY l.variant, DATE(l.server_time)',
            array_merge([$experiment['id'], $experiment['idsite']], $filter[1])
        );
        if (empty($visits)) {
            return null;
        }

        $measure = $experiment['primary_measure'];
        $outcomes = $measure === null ? [] : (new Measures())->count($experiment, $measure, $filter, true);
        $isAmount = $measure !== null && Measures::isAmount($measure);

        $byDay = [];
        foreach ($visits as $row) {
            $byDay[$row['day']][(int) $row['variant']]['visits'] = (int) $row['nb'];
        }
        foreach ($outcomes as $row) {
            $byDay[$row['day']][$row['variant']]['outcome'] = $isAmount ? $row['sum'] : $row['count'];
        }

        $days = [];
        for ($day = min(array_keys($byDay)); $day <= max(array_keys($byDay)); $day = gmdate('Y-m-d', strtotime($day . ' UTC') + 86400)) {
            $days[] = $day;
        }

        $series = [];
        foreach ($this->getVariantNames($experiment) as $number => $name) {
            $totalVisits = 0;
            $totalOutcome = 0;
            $values = [];
            foreach ($days as $day) {
                $totalVisits += $byDay[$day][$number]['visits'] ?? 0;
                $totalOutcome += $byDay[$day][$number]['outcome'] ?? 0;
                if ($measure === null) {
                    $values[] = (float) $totalVisits;
                } else {
                    $values[] = $totalVisits > 0 ? (float) ($totalOutcome / $totalVisits) : null;
                }
            }
            $series[] = ['variant' => $number, 'name' => $name, 'values' => $values];
        }

        return [
            'name' => $measure === null ? Piwik::translate('RebelABTesting_ResultVisits') : Measures::getName((int) $experiment['idsite'], $measure),
            'unit' => $measure === null ? Measures::UNIT_NUMBER : Measures::getUnit($measure),
            'days' => $days,
            'series' => $series,
        ];
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
