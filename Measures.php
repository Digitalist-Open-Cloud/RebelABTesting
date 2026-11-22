<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Exception;
use Piwik\Common;
use Piwik\Db;
use Piwik\Piwik;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;
use Piwik\Site;
use Piwik\Tracker\Action;

/**
 * What an experiment can be judged by: something Matomo records about a visit.
 *
 * A measure is a list with its `type` and, for a goal, its `idgoal`, or for an event, its `category`
 * and optionally its `action`.
 */
class Measures
{
    public const GOAL = 'goal';
    public const ORDERS = 'orders';
    public const REVENUE = 'revenue';
    public const EVENT = 'event';
    public const BOUNCE = 'bounce';
    public const SEARCH = 'search';

    public const MAX_GUARDRAILS = 2;

    private const ECOMMERCE_ORDER = 0;
    private const LOWER_IS_BETTER = [self::BOUNCE, self::SEARCH];

    /**
     * @param mixed $measure what a request handed over
     * @return array<string, mixed>|null the measure, or null for none
     */
    public static function normalize(int $idSite, $measure): ?array
    {
        $type = is_array($measure) ? (string) ($measure['type'] ?? '') : '';

        switch ($type) {
            case '':
                return null;
            case self::GOAL:
                $idGoal = (int) ($measure['idgoal'] ?? 0);
                if (!isset(GoalsApi::getInstance()->getGoals($idSite)[$idGoal])) {
                    break;
                }
                return ['type' => $type, 'idgoal' => $idGoal];
            case self::EVENT:
                $category = trim((string) ($measure['category'] ?? ''));
                if ($category === '' || mb_strlen($category) > 200) {
                    break;
                }
                return ['type' => $type, 'category' => $category, 'action' => mb_substr(trim((string) ($measure['action'] ?? '')), 0, 200)];
            case self::ORDERS:
            case self::REVENUE:
            case self::BOUNCE:
            case self::SEARCH:
                return ['type' => $type];
        }

        throw new Exception(Piwik::translate('RebelABTesting_ErrorMeasure'));
    }

    /**
     * @param mixed $measures what a request handed over
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeList(int $idSite, $measures): array
    {
        $list = [];
        foreach (is_array($measures) ? $measures : [] as $measure) {
            $measure = self::normalize($idSite, $measure);
            if ($measure !== null && !in_array($measure, $list, true)) {
                $list[] = $measure;
            }
        }

        return array_slice($list, 0, self::MAX_GUARDRAILS);
    }

    /**
     * The measures a form offers, by the value that stands for them. An event also needs its
     * category, which the form asks for.
     *
     * @return array<string, string>
     */
    public static function getChoices(int $idSite): array
    {
        $choices = ['' => Piwik::translate('RebelABTesting_MeasureNone')];
        foreach (GoalsApi::getInstance()->getGoals($idSite) as $goal) {
            $choices[self::GOAL . ':' . $goal['idgoal']] = self::getName($idSite, ['type' => self::GOAL, 'idgoal' => (int) $goal['idgoal']]);
        }
        foreach (Site::isEcommerceEnabledFor($idSite) ? [self::ORDERS, self::REVENUE] : [] as $type) {
            $choices[$type] = self::getName($idSite, ['type' => $type]);
        }
        foreach ([self::BOUNCE, self::SEARCH] as $type) {
            $choices[$type] = self::getName($idSite, ['type' => $type]);
        }
        $choices[self::EVENT] = Piwik::translate('RebelABTesting_MeasureEventChoice');

        return $choices;
    }

    /**
     * @param array<string, mixed>|null $measure
     * @return string the value that stands for the measure in a form
     */
    public static function toChoice(?array $measure): string
    {
        if ($measure === null) {
            return '';
        }

        return $measure['type'] === self::GOAL ? self::GOAL . ':' . $measure['idgoal'] : $measure['type'];
    }

    /**
     * @param string $choice a value of getChoices()
     * @return array<string, mixed>|null what normalize() takes
     */
    public static function fromChoice(string $choice, string $category = '', string $action = ''): ?array
    {
        if ($choice === '') {
            return null;
        }
        [$type, $idGoal] = explode(':', $choice, 2) + ['', ''];

        return ['type' => $type, 'idgoal' => $idGoal, 'category' => $category, 'action' => $action];
    }

    /**
     * @param array<string, mixed> $measure
     */
    public static function getName(int $idSite, array $measure): string
    {
        switch ($measure['type']) {
            case self::GOAL:
                $goals = GoalsApi::getInstance()->getGoals($idSite);
                $name = Common::unsanitizeInputValue($goals[$measure['idgoal']]['name'] ?? Piwik::translate('General_Unknown'));
                return Piwik::translate('RebelABTesting_MeasureGoal', $name);
            case self::EVENT:
                return Piwik::translate('RebelABTesting_MeasureEvent', $measure['category'] . ($measure['action'] === '' ? '' : ' / ' . $measure['action']));
            default:
                return Piwik::translate('RebelABTesting_Measure_' . $measure['type']);
        }
    }

    /**
     * @param array<string, mixed> $measure
     */
    public static function isLowerBetter(array $measure): bool
    {
        return in_array($measure['type'], self::LOWER_IS_BETTER, true);
    }

    /**
     * Whether the measure is an amount per visit and not a share of the visits.
     *
     * @param array<string, mixed> $measure
     */
    public static function isAmount(array $measure): bool
    {
        return $measure['type'] === self::REVENUE;
    }

    /**
     * @param array<string, mixed> $measure
     */
    public static function getKey(array $measure): string
    {
        return implode(':', $measure);
    }

    /**
     * What the visits of each variant did, from the moment they were in the experiment.
     *
     * @param array<string, mixed> $experiment
     * @param array<string, mixed> $measure
     * @return array<int, array{count: int, sum: float, sum_of_squares: float}> by variant: the visits
     *         with the outcome and, for an amount, the amounts of the visits added up
     */
    public function count(array $experiment, array $measure): array
    {
        $log = Common::prefixTable(LogExperiment::TABLE);
        $bind = [$experiment['id'], $experiment['idsite']];
        $where = 'l.idexperiment = ? AND l.idsite = ?';

        switch ($measure['type']) {
            case self::GOAL:
            case self::ORDERS:
            case self::REVENUE:
                $conversions = "SELECT l.idvisit, l.variant, SUM(c.revenue) AS amount
                                  FROM $log l
                                  INNER JOIN " . Common::prefixTable('log_conversion') . " c
                                    ON c.idvisit = l.idvisit AND c.idsite = l.idsite AND c.server_time >= l.server_time
                                 WHERE $where AND c.idgoal = ?
                                 GROUP BY l.idvisit, l.variant";
                $sql = "SELECT variant, COUNT(*) AS nb, SUM(amount) AS total, SUM(amount * amount) AS squares FROM ($conversions) visits GROUP BY variant";
                $bind[] = $measure['type'] === self::GOAL ? $measure['idgoal'] : self::ECOMMERCE_ORDER;
                break;
            case self::EVENT:
                $category = $this->getIdAction($measure['category'], Action::TYPE_EVENT_CATEGORY);
                $action = $measure['action'] === '' ? null : $this->getIdAction($measure['action'], Action::TYPE_EVENT_ACTION);
                if ($category === 0 || $action === 0) {
                    return [];
                }
                $sql = "SELECT l.variant, COUNT(DISTINCT l.idvisit) AS nb, 0 AS total, 0 AS squares
                          FROM $log l
                          INNER JOIN " . Common::prefixTable('log_link_visit_action') . " a
                            ON a.idvisit = l.idvisit AND a.server_time >= l.server_time
                         WHERE $where AND a.idaction_event_category = ?" . ($action === null ? '' : ' AND a.idaction_event_action = ?') . '
                         GROUP BY l.variant';
                $bind[] = $category;
                if ($action !== null) {
                    $bind[] = $action;
                }
                break;
            default:
                $condition = $measure['type'] === self::BOUNCE ? 'v.visit_total_actions <= 1' : 'v.visit_total_searches > 0';
                $sql = "SELECT l.variant, COUNT(*) AS nb, 0 AS total, 0 AS squares
                          FROM $log l
                          INNER JOIN " . Common::prefixTable('log_visit') . " v ON v.idvisit = l.idvisit
                         WHERE $where AND $condition
                         GROUP BY l.variant";
        }

        $counts = [];
        foreach (Db::fetchAll($sql, $bind) as $row) {
            $counts[(int) $row['variant']] = ['count' => (int) $row['nb'], 'sum' => (float) $row['total'], 'sum_of_squares' => (float) $row['squares']];
        }

        return $counts;
    }

    /**
     * @return int the ID Matomo has given the name, or 0 when it has never been tracked
     */
    private function getIdAction(string $name, int $type): int
    {
        return (int) Db::fetchOne(
            'SELECT idaction FROM ' . Common::prefixTable('log_action') . ' WHERE hash = CRC32(?) AND name = ? AND type = ?',
            [$name, $name, $type]
        );
    }
}
