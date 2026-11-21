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
    public const ECOMMERCE_ORDER = 0;

    /**
     * @param array<string, mixed> $experiment
     * @return array{variants: array<int, array<string, mixed>>, goals: array<int, array<string, mixed>>}
     */
    public function get(array $experiment): array
    {
        $bind = [$experiment['id'], $experiment['idsite']];
        $log = Common::prefixTable(LogExperiment::TABLE);

        // ponytail: reads the raw log, so visits whose log Matomo has deleted are gone from the result;
        // archive per experiment if tests run longer than the logs are kept
        $visits = array_column(
            Db::fetchAll("SELECT variant, COUNT(*) AS nb_visits FROM $log WHERE idexperiment = ? AND idsite = ? GROUP BY variant", $bind),
            'nb_visits',
            'variant'
        );
        $conversions = Db::fetchAll(
            "SELECT l.variant, c.idgoal, COUNT(DISTINCT c.idvisit) AS nb_visits_converted, COUNT(*) AS nb_conversions
               FROM $log l
               INNER JOIN " . Common::prefixTable('log_conversion') . " c ON c.idvisit = l.idvisit AND c.idsite = l.idsite
              WHERE l.idexperiment = ? AND l.idsite = ? AND c.idgoal >= " . self::ECOMMERCE_ORDER . "
              GROUP BY l.variant, c.idgoal",
            $bind
        );

        $variants = [];
        foreach ($this->getVariantNames($experiment) as $number => $name) {
            $variants[$number] = ['variant' => $number, 'name' => $name, 'nb_visits' => (int) ($visits[$number] ?? 0)];
        }

        $byGoal = [];
        foreach ($conversions as $row) {
            $byGoal[(int) $row['idgoal']][(int) $row['variant']] = $row;
        }
        ksort($byGoal);
        $primary = $experiment['primary_goal'];
        if ($primary !== null) {
            // The goal the experiment is judged by comes first, also before it has conversions.
            $byGoal = [$primary => $byGoal[$primary] ?? []] + $byGoal;
        }

        $names = array_column(GoalsApi::getInstance()->getGoals($experiment['idsite']), 'name', 'idgoal');
        $names[self::ECOMMERCE_ORDER] = Piwik::translate('General_EcommerceOrders');

        $goals = [];
        foreach ($byGoal as $idGoal => $rows) {
            $goals[] = [
                'idgoal' => $idGoal,
                'name' => Common::unsanitizeInputValue($names[$idGoal] ?? Piwik::translate('General_Unknown')),
                'is_primary' => $idGoal === $primary,
                'variants' => $this->compare($variants, $rows),
            ];
        }

        return ['variants' => array_values($variants), 'goals' => $goals];
    }

    /**
     * @param array<int, array<string, mixed>> $variants
     * @param array<int, array<string, mixed>> $rows the conversions of a goal by variant
     * @return array<int, array<string, mixed>>
     */
    private function compare(array $variants, array $rows): array
    {
        $result = [];
        foreach ($variants as $number => $variant) {
            $converted = (int) ($rows[$number]['nb_visits_converted'] ?? 0);
            $line = $variant + [
                'nb_visits_converted' => $converted,
                'nb_conversions' => (int) ($rows[$number]['nb_conversions'] ?? 0),
                'conversion_rate' => $variant['nb_visits'] > 0 ? $converted / $variant['nb_visits'] : 0.0,
                'uplift' => null,
                'chance_to_beat_original' => null,
                'verdict' => null,
            ];

            if ($number > 1) {
                $original = $result[0];
                $chance = Statistics::chanceToBeat($original['nb_visits'], $original['nb_visits_converted'], $variant['nb_visits'], $converted);

                $line['uplift'] = $original['conversion_rate'] > 0 ? $line['conversion_rate'] / $original['conversion_rate'] - 1 : null;
                $line['chance_to_beat_original'] = $chance;
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
