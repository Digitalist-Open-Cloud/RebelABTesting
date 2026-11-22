<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelExperiments;

use Exception;
use Piwik\Common;
use Piwik\ArchiveProcessor\Parameters;
use Piwik\Container\StaticContainer;
use Piwik\DataAccess\LogAggregator;
use Piwik\Db;
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Piwik;
use Piwik\Plugin\Manager;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\RebelExperiments\Dao\LogExperiment;
use Piwik\Plugins\RebelFunnels\Archiving\FunnelCounter;
use Piwik\Plugins\RebelFunnels\Model as FunnelsModel;
use Piwik\Segment;
use Piwik\Segment\SegmentExpression;
use Piwik\Site;
use Piwik\Tracker\Action;

/**
 * What an experiment can be judged by: something Matomo records about a visit.
 *
 * A measure is a list with its `type` and, for a goal, its `idgoal`, for an event, its `category`
 * and optionally its `action`, for a funnel of RebelFunnels, its `idfunnel`, or for a form of
 * UserFeedback, its `idform`.
 */
class Measures
{
    public const GOAL = 'goal';
    public const ORDERS = 'orders';
    public const REVENUE = 'revenue';
    public const EVENT = 'event';
    public const BOUNCE = 'bounce';
    public const SEARCH = 'search';
    public const GOAL_REVENUE = 'goal_revenue';
    public const CONVERSIONS = 'conversions';
    public const TOTAL_REVENUE = 'total_revenue';
    public const PAGEVIEWS = 'pageviews';
    public const VISIT_LENGTH = 'visit_length';
    public const FUNNEL = 'funnel';
    public const FEEDBACK = 'feedback';

    public const UNIT_SHARE = 'share';
    public const UNIT_MONEY = 'money';
    public const UNIT_NUMBER = 'number';
    public const UNIT_SECONDS = 'seconds';

    public const MAX_GUARDRAILS = 2;

    private const ECOMMERCE_ORDER = 0;
    private const FEEDBACK_FORMS = 'user_feedback_forms';
    private const FEEDBACK_RESULTS = 'user_feedback_results';
    // The answers of a form that can be counted. What a visitor writes is left out.
    private const FEEDBACK_CHOICES = ['checkbox-group', 'radio-group', 'select', 'starRating', 'number'];
    private const MAX_FEEDBACK_ANSWERS = 10000;
    private const LOWER_IS_BETTER = [self::BOUNCE, self::SEARCH];
    private const UNITS = [
        self::REVENUE => self::UNIT_MONEY,
        self::GOAL_REVENUE => self::UNIT_MONEY,
        self::TOTAL_REVENUE => self::UNIT_MONEY,
        self::PAGEVIEWS => self::UNIT_NUMBER,
        self::VISIT_LENGTH => self::UNIT_SECONDS,
    ];

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
            case self::GOAL_REVENUE:
                $idGoal = (int) ($measure['idgoal'] ?? 0);
                if (!isset(GoalsApi::getInstance()->getGoals($idSite)[$idGoal])) {
                    break;
                }
                return ['type' => $type, 'idgoal' => $idGoal];
            case self::FUNNEL:
                $idFunnel = (int) ($measure['idfunnel'] ?? 0);
                if (!isset(self::getFunnels($idSite)[$idFunnel])) {
                    break;
                }
                return ['type' => $type, 'idfunnel' => $idFunnel];
            case self::FEEDBACK:
                $idForm = (int) ($measure['idform'] ?? 0);
                if (!isset(self::getFeedbackForms($idSite)[$idForm])) {
                    break;
                }
                return ['type' => $type, 'idform' => $idForm];
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
            case self::CONVERSIONS:
            case self::TOTAL_REVENUE:
            case self::PAGEVIEWS:
            case self::VISIT_LENGTH:
                return ['type' => $type];
        }

        throw new Exception(Piwik::translate('RebelExperiments_ErrorMeasure'));
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
        $choices = ['' => Piwik::translate('RebelExperiments_MeasureNone')];
        foreach (GoalsApi::getInstance()->getGoals($idSite) as $goal) {
            $choices[self::GOAL . ':' . $goal['idgoal']] = self::getName($idSite, ['type' => self::GOAL, 'idgoal' => (int) $goal['idgoal']]);
        }
        foreach (GoalsApi::getInstance()->getGoals($idSite) as $goal) {
            $choices[self::GOAL_REVENUE . ':' . $goal['idgoal']] = self::getName($idSite, ['type' => self::GOAL_REVENUE, 'idgoal' => (int) $goal['idgoal']]);
        }
        foreach (Site::isEcommerceEnabledFor($idSite) ? [self::ORDERS, self::REVENUE] : [] as $type) {
            $choices[$type] = self::getName($idSite, ['type' => $type]);
        }
        foreach ([self::CONVERSIONS, self::TOTAL_REVENUE, self::PAGEVIEWS, self::VISIT_LENGTH, self::BOUNCE, self::SEARCH] as $type) {
            $choices[$type] = self::getName($idSite, ['type' => $type]);
        }
        $choices[self::EVENT] = Piwik::translate('RebelExperiments_MeasureEventChoice');
        foreach (self::getFunnels($idSite) as $idFunnel => $funnel) {
            $choices[self::FUNNEL . ':' . $idFunnel] = self::getName($idSite, ['type' => self::FUNNEL, 'idfunnel' => $idFunnel]);
        }
        foreach (self::getFeedbackForms($idSite) as $idForm => $name) {
            $choices[self::FEEDBACK . ':' . $idForm] = self::getName($idSite, ['type' => self::FEEDBACK, 'idform' => $idForm]);
        }

        return $choices;
    }

    /**
     * @return array<int, array<string, mixed>> the funnels of RebelFunnels for the website by ID,
     *         none when that plugin is not active
     */
    private static function getFunnels(int $idSite): array
    {
        if (!Manager::getInstance()->isPluginActivated('RebelFunnels')) {
            return [];
        }

        return array_column((new FunnelsModel())->getFunnels($idSite), null, 'idfunnel');
    }

    /**
     * @return array<int, string> the names of the forms of UserFeedback for the website by ID, none
     *         when that plugin is not active
     */
    private static function getFeedbackForms(int $idSite): array
    {
        if (!Manager::getInstance()->isPluginActivated('UserFeedback')) {
            return [];
        }

        return array_column(
            Db::fetchAll('SELECT id, name FROM ' . Common::prefixTable(self::FEEDBACK_FORMS) . ' WHERE site_id = ? ORDER BY name', [$idSite]),
            'name',
            'id'
        );
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

        $id = $measure['idgoal'] ?? $measure['idfunnel'] ?? $measure['idform'] ?? null;

        return $id === null ? $measure['type'] : $measure['type'] . ':' . $id;
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
        [$type, $id] = explode(':', $choice, 2) + ['', ''];

        return ['type' => $type, 'idgoal' => $id, 'idfunnel' => $id, 'idform' => $id, 'category' => $category, 'action' => $action];
    }

    /**
     * @param array<string, mixed> $measure
     */
    public static function getName(int $idSite, array $measure): string
    {
        switch ($measure['type']) {
            case self::GOAL:
            case self::GOAL_REVENUE:
                $goals = GoalsApi::getInstance()->getGoals($idSite);
                $name = Common::unsanitizeInputValue($goals[$measure['idgoal']]['name'] ?? Piwik::translate('General_Unknown'));
                return Piwik::translate($measure['type'] === self::GOAL ? 'RebelExperiments_MeasureGoal' : 'RebelExperiments_MeasureGoalRevenue', $name);
            case self::FUNNEL:
                $name = self::getFunnels($idSite)[$measure['idfunnel']]['name'] ?? Piwik::translate('General_Unknown');
                return Piwik::translate('RebelExperiments_MeasureFunnel', Common::unsanitizeInputValue($name));
            case self::FEEDBACK:
                $name = self::getFeedbackForms($idSite)[$measure['idform']] ?? Piwik::translate('General_Unknown');
                return Piwik::translate('RebelExperiments_MeasureFeedback', Common::unsanitizeInputValue($name));
            case self::EVENT:
                return Piwik::translate('RebelExperiments_MeasureEvent', $measure['category'] . ($measure['action'] === '' ? '' : ' / ' . $measure['action']));
            default:
                return Piwik::translate('RebelExperiments_Measure_' . $measure['type']);
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
        return isset(self::UNITS[$measure['type']]);
    }

    /**
     * @param array<string, mixed> $measure
     * @return string how its value reads: as a share of the visits, or as money, a number or
     *                seconds per visit
     */
    public static function getUnit(array $measure): string
    {
        return self::UNITS[$measure['type']] ?? self::UNIT_SHARE;
    }

    /**
     * Limits the visits of an experiment to those of a segment.
     *
     * @param array<string, mixed> $experiment
     * @return array{0: string, 1: array<int, mixed>, 2: string} what to add to the condition of a
     *         query on the log of the plugin as `l`, the values it binds, and the segment itself
     */
    public static function getSegmentFilter(array $experiment, string $segment): array
    {
        if (trim($segment) === '') {
            return ['', [], ''];
        }

        $query = (new Segment($segment, [$experiment['idsite']]))->getSelectQuery(
            'log_visit.idvisit',
            'log_visit',
            'log_visit.idsite = ? AND log_visit.visit_last_action_time >= ? AND log_visit.visit_first_action_time <= ?',
            [$experiment['idsite'], $experiment['from_date'] . ' 00:00:00', $experiment['to_date'] . ' 23:59:59']
        );

        return [' AND l.idvisit IN (SELECT idvisit FROM (' . $query['sql'] . ') segment)', $query['bind'], $segment];
    }

    /**
     * @param array<string, mixed> $measure
     */
    public static function getKey(array $measure): string
    {
        return implode(':', $measure);
    }

    /**
     * What the visits of each variant did. Goals, revenue and events count from the moment the visit
     * was in the experiment; what Matomo keeps for a visit as a whole, a funnel and an answered form
     * count for the whole visit.
     *
     * @param array<string, mixed> $experiment
     * @param array<string, mixed> $measure
     * @param array{0: string, 1: array<int, mixed>, 2?: string} $filter see getSegmentFilter()
     * @param bool $byDay whether to tell the days apart, by the day the visit got into the experiment.
     *                    A funnel is not counted by day
     * @return array<int, array{variant: int, day: ?string, count: int, sum: float, sum_of_squares: float}>
     *         for each variant, and day: the visits with the outcome and, for an amount, the
     *         amounts of the visits added up
     */
    public function count(array $experiment, array $measure, array $filter = ['', []], bool $byDay = false): array
    {
        $log = Common::prefixTable(LogExperiment::TABLE);
        $where = 'l.idexperiment = ? AND l.idsite = ?' . $filter[0];
        $bind = array_merge([$experiment['id'], $experiment['idsite']], $filter[1]);
        $day = $byDay ? ', DATE(l.server_time) AS day' : '';
        $visit = 'l.idvisit, l.variant' . ($byDay ? ', DATE(l.server_time)' : '');

        switch ($measure['type']) {
            case self::GOAL:
            case self::GOAL_REVENUE:
            case self::ORDERS:
            case self::REVENUE:
            case self::CONVERSIONS:
            case self::TOTAL_REVENUE:
                $goal = 'c.idgoal = ?';
                if ($measure['type'] === self::CONVERSIONS) {
                    $goal = 'c.idgoal > ' . self::ECOMMERCE_ORDER;
                } elseif ($measure['type'] === self::TOTAL_REVENUE) {
                    $goal = 'c.idgoal >= ' . self::ECOMMERCE_ORDER;
                } else {
                    $bind[] = $measure['idgoal'] ?? self::ECOMMERCE_ORDER;
                }
                $visits = "SELECT l.variant$day, SUM(c.revenue) AS amount
                             FROM $log l
                             INNER JOIN " . Common::prefixTable('log_conversion') . " c
                               ON c.idvisit = l.idvisit AND c.idsite = l.idsite AND c.server_time >= l.server_time
                            WHERE $where AND $goal
                            GROUP BY $visit";
                break;
            case self::FUNNEL:
                return $byDay ? [] : $this->countFunnel($experiment, $measure, $filter[2] ?? '');
            case self::FEEDBACK:
                if (!Manager::getInstance()->isPluginActivated('UserFeedback')) {
                    return [];
                }
                // ponytail: an answer counts whenever in the visit it was given; its time is kept
                // in the time zone of the database, so it cannot be compared with the log
                $visits = "SELECT l.variant$day, 0 AS amount
                             FROM $log l
                             INNER JOIN " . Common::prefixTable(self::FEEDBACK_RESULTS) . " r
                               ON r.idvisit = l.idvisit AND r.site_id = l.idsite
                            WHERE $where AND r.form_id = ?
                            GROUP BY $visit";
                $bind[] = $measure['idform'];
                break;
            case self::EVENT:
                $category = $this->getIdAction($measure['category'], Action::TYPE_EVENT_CATEGORY);
                $action = $measure['action'] === '' ? null : $this->getIdAction($measure['action'], Action::TYPE_EVENT_ACTION);
                if ($category === 0 || $action === 0) {
                    return [];
                }
                $visits = "SELECT l.variant$day, 0 AS amount
                             FROM $log l
                             INNER JOIN " . Common::prefixTable('log_link_visit_action') . " a
                               ON a.idvisit = l.idvisit AND a.server_time >= l.server_time
                            WHERE $where AND a.idaction_event_category = ?" . ($action === null ? '' : ' AND a.idaction_event_action = ?') . "
                            GROUP BY $visit";
                $bind[] = $category;
                if ($action !== null) {
                    $bind[] = $action;
                }
                break;
            case self::PAGEVIEWS:
                $visits = "SELECT l.variant$day, COUNT(*) AS amount
                             FROM $log l
                             INNER JOIN " . Common::prefixTable('log_link_visit_action') . " a ON a.idvisit = l.idvisit
                             INNER JOIN " . Common::prefixTable('log_action') . " u ON u.idaction = a.idaction_url AND u.type = " . Action::TYPE_PAGE_URL . "
                            WHERE $where AND a.idaction_event_category IS NULL
                            GROUP BY $visit";
                break;
            default:
                $conditions = [
                    self::BOUNCE => 'v.visit_total_actions <= 1',
                    self::SEARCH => 'v.visit_total_searches > 0',
                    self::VISIT_LENGTH => 'v.visit_total_time > 0',
                ];
                $visits = "SELECT l.variant$day, " . ($measure['type'] === self::VISIT_LENGTH ? 'v.visit_total_time' : '0') . " AS amount
                             FROM $log l
                             INNER JOIN " . Common::prefixTable('log_visit') . " v ON v.idvisit = l.idvisit
                            WHERE $where AND " . $conditions[$measure['type']];
        }

        $group = 'variant' . ($byDay ? ', day' : '');
        $rows = Db::fetchAll(
            'SELECT variant' . ($byDay ? ', day' : ', NULL AS day') . ", COUNT(*) AS nb, SUM(amount) AS total, SUM(amount * amount) AS squares
               FROM ($visits) visits
              GROUP BY $group
              ORDER BY $group",
            $bind
        );

        return array_map(function (array $row): array {
            return [
                'variant' => (int) $row['variant'],
                'day' => $row['day'],
                'count' => (int) $row['nb'],
                'sum' => (float) $row['total'],
                'sum_of_squares' => (float) $row['squares'],
            ];
        }, $rows);
    }

    /**
     * The visits of each variant that went through all steps of a funnel, counted by RebelFunnels.
     *
     * @param array<string, mixed> $experiment
     * @param array<string, mixed> $measure
     * @return array<int, array{variant: int, day: null, count: int, sum: float, sum_of_squares: float}>
     */
    private function countFunnel(array $experiment, array $measure, string $segment): array
    {
        $idSite = (int) $experiment['idsite'];
        $funnel = self::getFunnels($idSite)[$measure['idfunnel']] ?? null;
        if ($funnel === null) {
            return [];
        }

        $period = PeriodFactory::build('range', $experiment['from_date'] . ',' . $experiment['to_date'], Site::getTimezoneFor($idSite));
        $counter = StaticContainer::get(FunnelCounter::class);

        // ponytail: one count of the whole experiment per variant, every time the result is shown;
        // keep the counts of days that are over if that gets slow
        $rows = [];
        for ($variant = 1; $variant <= count($experiment['variants']) + 1; $variant++) {
            $definition = 'experimentId==' . (int) $experiment['id'] . ';experimentVariant==' . $variant;
            foreach ([$funnel['segment_filter'], $segment] as $more) {
                $definition = Segment::combine($definition, SegmentExpression::AND_DELIMITER, $more);
            }
            $logAggregator = new LogAggregator(new Parameters(
                new Site($idSite),
                $period,
                new Segment($definition, [$idSite], $period->getDateTimeStart(), $period->getDateTimeEnd())
            ));
            $logAggregator->setQueryOriginHint('RebelExperimentsFunnel');

            $steps = $counter->count($logAggregator, $funnel['steps']);
            $completed = (int) (end($steps)['nb_visits'] ?? 0);
            if ($completed > 0) {
                $rows[] = ['variant' => $variant, 'day' => null, 'count' => $completed, 'sum' => 0.0, 'sum_of_squares' => 0.0];
            }
        }

        return $rows;
    }

    /**
     * What the visits of each variant answered in a form of UserFeedback, for the questions with
     * answers to choose from.
     *
     * @param array<string, mixed> $experiment
     * @param array{0: string, 1: array<int, mixed>, 2?: string} $filter see getSegmentFilter()
     * @return array<int, array{question: string, answer: string, counts: array<int, int>}> the
     *         counts by the number of the variant
     */
    public function getFeedbackAnswers(array $experiment, int $idForm, array $filter = ['', []]): array
    {
        if (!Manager::getInstance()->isPluginActivated('UserFeedback')) {
            return [];
        }

        $content = (string) Db::fetchOne('SELECT content FROM ' . Common::prefixTable(self::FEEDBACK_FORMS) . ' WHERE id = ? AND site_id = ?', [$idForm, $experiment['idsite']]);
        $questions = [];
        $choices = [];
        foreach ((array) json_decode(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true) as $field) {
            $name = is_array($field) ? (string) ($field['name'] ?? '') : '';
            if ($name === '') {
                continue;
            }
            $questions[$name] = (string) ($field['label'] ?? $name);
            foreach ((array) ($field['values'] ?? []) as $choice) {
                $choices[$name][(string) ($choice['value'] ?? '')] = (string) ($choice['label'] ?? $choice['value'] ?? '');
            }
        }

        // ponytail: reads the newest answers into memory; count in the database if forms get more
        $rows = Db::fetchAll(
            'SELECT l.variant, r.result
               FROM ' . Common::prefixTable(LogExperiment::TABLE) . ' l
               INNER JOIN ' . Common::prefixTable(self::FEEDBACK_RESULTS) . ' r ON r.idvisit = l.idvisit AND r.site_id = l.idsite
              WHERE l.idexperiment = ? AND l.idsite = ?' . $filter[0] . ' AND r.form_id = ?
              ORDER BY r.id DESC LIMIT ' . self::MAX_FEEDBACK_ANSWERS,
            array_merge([$experiment['id'], $experiment['idsite']], $filter[1], [$idForm])
        );

        $answers = [];
        foreach ($rows as $row) {
            foreach ((array) json_decode((string) $row['result'], true) as $field) {
                if (!is_array($field) || !in_array($field['type'] ?? '', self::FEEDBACK_CHOICES, true)) {
                    continue;
                }
                $name = (string) ($field['name'] ?? '');
                $question = trim(strip_tags(html_entity_decode($questions[$name] ?? (string) ($field['label'] ?? $name))));
                foreach ((array) ($field['userData'] ?? []) as $answer) {
                    if (!is_scalar($answer) || (string) $answer === '') {
                        continue;
                    }
                    $answer = trim(strip_tags($choices[$name][(string) $answer] ?? (string) $answer));
                    $key = $question . "\n" . $answer;
                    $answers[$key] = $answers[$key] ?? ['question' => $question, 'answer' => $answer, 'counts' => []];
                    $answers[$key]['counts'][(int) $row['variant']] = ($answers[$key]['counts'][(int) $row['variant']] ?? 0) + 1;
                }
            }
        }
        ksort($answers, SORT_NATURAL);
        foreach ($answers as &$answer) {
            ksort($answer['counts']);
        }

        return array_values($answers);
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
