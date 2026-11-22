<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelExperiments;

use Exception;
use Piwik\Date;
use Piwik\Piwik;
use Piwik\Plugins\RebelExperiments\Dao\Experiments;
use Piwik\Plugins\RebelExperiments\Validation\ExperimentValidator;

/**
 * Experiments, which show variants of a page to a share of the visitors, and how the variants did.
 *
 * @method static \Piwik\Plugins\RebelExperiments\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    /**
     * The CSS and JavaScript of a variant have to arrive as they were written.
     */
    protected $autoSanitizeInputParams = false;

    /**
     * @var Experiments
     */
    private $experiments;

    public function __construct()
    {
        $this->experiments = new Experiments();
    }

    /**
     * Adds an experiment. Needs write access to the website, and admin access when a variant has CSS
     * or JavaScript.
     *
     * @param string $fromDate the first day, as YYYY-MM-DD in UTC
     * @param string $toDate the last day, as YYYY-MM-DD in UTC
     * @param array $variants one or more variants, each with a `name`, a list of `changes` (each with
     *                        `selector`, `action` and `value`) and, for admins, `css` and `js`
     * @param int $trafficShare the percentage of visitors that take part, split evenly between the
     *                          original and the variants
     * @param string $targetMatch how the address of a page is compared with $targetValue: any,
     *                            equals, contains, starts_with or regexp
     * @param array $primaryMeasure what the experiment is judged by: its `type` (goal, orders, revenue,
     *                              event, bounce or search) and, for a goal, its `idgoal`, or for an
     *                              event, its `category` and optionally its `action`. Empty for none
     * @param string $kind `ab` for a test of variants, `rollout` for one change that a share of the
     *                     visitors gets while the rest are counted without it, `fake_door` for a button to something that
     *                     does not exist yet, to see how many visitors want it, `watch` to count the
     *                     visits that see and click an element the page already has
     * @param array $door the fake door: `selector` of the element it is put next to, `position`
     *                    (after, before, append or prepend), `element` (button or link), `label`,
     *                    `css_class` and the `message` shown after a click. For `watch`: the `selector` of the
     *                    element to watch
     * @param array $guardrails up to two measures that must not get worse
     * @param int $confidence how sure to be before a variant is called better or worse: 90, 95 or 99
     * @param string $excludeMatch how the address of a page the experiment leaves out is compared
     *                             with $excludeValue: none, equals, contains, starts_with or regexp
     * @param bool $notify whether to email the one who adds the experiment when a variant is found
     *                     better or worse on the primary measure, and when the experiment ends
     * @param array $audience the visitors the experiment is for: `visitor` (new or returning),
     *                        `devices` (desktop, tablet, mobile), `campaign` and `referrer` (a text
     *                        each has to contain) and `languages` (codes such as sv or en-gb).
     *                        Empty for everyone
     * @return int the ID of the experiment
     */
    public function insertExperiment(
        int $idSite,
        string $name,
        string $hypothesis,
        string $description,
        string $fromDate,
        string $toDate,
        array $variants = [],
        int $trafficShare = 100,
        string $targetMatch = 'any',
        string $targetValue = '',
        array $primaryMeasure = [],
        string $kind = 'ab',
        array $door = [],
        array $guardrails = [],
        int $confidence = 95,
        string $excludeMatch = 'none',
        string $excludeValue = '',
        bool $notify = true,
        array $audience = []
    ): int {
        $this->checkCanSave($idSite, $variants);

        $fields = self::toFields(
            $idSite,
            $name,
            $hypothesis,
            $description,
            $fromDate,
            $toDate,
            $variants,
            $trafficShare,
            $targetMatch,
            $targetValue,
            $primaryMeasure,
            $kind,
            $door,
            $guardrails,
            $confidence,
            $excludeMatch,
            $excludeValue,
            $notify,
            $audience
        );

        return $this->experiments->insertExperiment($idSite, $fields);
    }

    /**
     * Changes an experiment. An experiment that has CSS or JavaScript can only be changed by an
     * admin of the website.
     *
     * @param array $variants see insertExperiment
     */
    public function updateExperiment(
        int $id,
        int $idSite,
        string $name,
        string $hypothesis,
        string $description,
        string $fromDate,
        string $toDate,
        array $variants = [],
        int $trafficShare = 100,
        string $targetMatch = 'any',
        string $targetValue = '',
        array $primaryMeasure = [],
        string $kind = 'ab',
        array $door = [],
        array $guardrails = [],
        int $confidence = 95,
        string $excludeMatch = 'none',
        string $excludeValue = '',
        bool $notify = true,
        array $audience = []
    ): void {
        $this->checkCanSave($idSite, $variants, $this->experiments->getById($id, $idSite));
        $fields = self::toFields(
            $idSite,
            $name,
            $hypothesis,
            $description,
            $fromDate,
            $toDate,
            $variants,
            $trafficShare,
            $targetMatch,
            $targetValue,
            $primaryMeasure,
            $kind,
            $door,
            $guardrails,
            $confidence,
            $excludeMatch,
            $excludeValue,
            $notify,
            $audience
        );
        $this->experiments->updateExperiment($id, $idSite, $fields);
    }

    /**
     * Shows a variant of an A/B test to everyone the test was for, as a rollout of its own that runs
     * for a year. The test is ended.
     *
     * @param int $variant the number of the variant: 2 for the first one
     * @return int the ID of the rollout
     */
    public function rollOutVariant(int $idSite, int $id, int $variant): int
    {
        Piwik::checkUserHasWriteAccess($idSite);

        $experiment = $this->getExistingExperiment($idSite, $id);
        $chosen = $experiment['variants'][$variant - 2] ?? null;
        if ($experiment['kind'] !== ExperimentValidator::KIND_AB || $chosen === null) {
            throw new Exception(Piwik::translate('RebelExperiments_ErrorRollOut'));
        }
        if (ExperimentValidator::hasCode([$chosen])) {
            Piwik::checkUserHasAdminAccess($idSite);
        }

        $chosenName = $chosen['name'] !== '' ? $chosen['name'] : Piwik::translate('RebelExperiments_VariantNumber', $variant - 1);
        $name = $experiment['name'] . ': ' . $chosenName;
        $idRollout = $this->experiments->insertExperiment($idSite, [
            'kind' => ExperimentValidator::KIND_ROLLOUT,
            'name' => mb_substr($name, 0, 230) . ' (' . Date::now()->toString() . ')',
            'hypothesis' => '',
            'description' => Piwik::translate('RebelExperiments_RolledOutFrom', $experiment['name']),
            'from_date' => Date::now()->toString(),
            'to_date' => Date::now()->addPeriod(1, 'year')->toString(),
            'variants' => [$chosen],
            'traffic_share' => 100,
            'notify' => false,
        ] + array_intersect_key($experiment, array_flip(['target_match', 'target_value', 'exclude_match', 'exclude_value', 'audience', 'primary_measure', 'guardrails', 'confidence'])));
        $this->experiments->stopExperiment($id, $idSite);
        $this->experiments->setDecision($id, $idSite, 'adopt', Piwik::translate('RebelExperiments_DecisionRolledOut', $chosenName));

        return $idRollout;
    }

    /**
     * Ends an experiment now.
     */
    public function stopExperiment(int $id, int $idSite): void
    {
        Piwik::checkUserHasWriteAccess($idSite);
        $this->experiments->stopExperiment($id, $idSite);
    }

    /**
     * Records what was decided about an experiment and why.
     *
     * @param string $decision `adopt` to use the change, `keep` to keep things as they are,
     *                         `inconclusive` when nothing could be concluded, or empty to take the
     *                         decision back
     */
    public function setDecision(int $id, int $idSite, string $decision, string $note = ''): void
    {
        Piwik::checkUserHasWriteAccess($idSite);
        $this->experiments->setDecision($id, $idSite, $decision, $note);
    }

    public function deleteExperiment(int $id, int $idSite): void
    {
        Piwik::checkUserHasWriteAccess($idSite);
        $this->experiments->deleteExperiment($id, $idSite);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExperiment(int $id, int $idSite): ?array
    {
        Piwik::checkUserHasWriteAccess($idSite);

        return $this->experiments->getById($id, $idSite);
    }

    /**
     * The experiments of a website and what was decided about them, without what their variants do.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExperiments(int $idSite): array
    {
        Piwik::checkUserHasViewAccess($idSite);

        return array_map(function (array $experiment): array {
            return array_intersect_key($experiment, array_flip(['id', 'idsite', 'name', 'kind', 'from_date', 'to_date', 'status', 'decision', 'decision_note']))
                + ['has_code' => ExperimentValidator::hasCode($experiment['variants'])];
        }, $this->experiments->getAllForSite($idSite));
    }

    /**
     * Visits and visitors per variant, whether the visitors are split evenly, and for each measure
     * the value per variant, the difference to the original and how likely the variant is to be
     * better than the original. For a fake door: the visits it was shown to and their clicks.
     *
     * @param string $segment limits the result to the visits of a segment
     * @return array<string, mixed>
     */
    public function getExperimentResult(int $idSite, int $id, string $segment = ''): array
    {
        Piwik::checkUserHasViewAccess($idSite);

        return (new Results())->get($this->getExistingExperiment($idSite, $id), $segment);
    }

    /**
     * How the primary measure of each variant developed day by day, counted from the first day of the
     * experiment. Without a primary measure: the visits of each variant.
     *
     * @param string $segment limits it to the visits of a segment
     * @return array<string, mixed>|null null when there is nothing to show
     */
    public function getExperimentEvolution(int $idSite, int $id, string $segment = ''): ?array
    {
        Piwik::checkUserHasViewAccess($idSite);

        return (new Results())->getEvolution($this->getExistingExperiment($idSite, $id), $segment);
    }

    /**
     * @return array<string, mixed>
     */
    private function getExistingExperiment(int $idSite, int $id): array
    {
        $experiment = $this->experiments->getById($id, $idSite);
        if ($experiment === null) {
            throw new Exception(Piwik::translate('RebelExperiments_ExperimentNotFound'));
        }

        return $experiment;
    }

    /**
     * @param array<int, mixed> $variants
     * @param array<string, mixed> $primaryMeasure
     * @param array<string, mixed> $door
     * @param array<int, mixed> $guardrails
     * @param array<string, mixed> $audience
     * @return array<string, mixed>
     */
    private static function toFields(
        int $idSite,
        string $name,
        string $hypothesis,
        string $description,
        string $fromDate,
        string $toDate,
        array $variants,
        int $trafficShare,
        string $targetMatch,
        string $targetValue,
        array $primaryMeasure,
        string $kind,
        array $door,
        array $guardrails,
        int $confidence,
        string $excludeMatch,
        string $excludeValue,
        bool $notify,
        array $audience
    ): array {
        return [
            'kind' => $kind,
            'door' => $door,
            'primary_measure' => Measures::normalize($idSite, $primaryMeasure),
            'guardrails' => Measures::normalizeList($idSite, $guardrails),
            'confidence' => $confidence,
            'exclude_match' => $excludeMatch,
            'exclude_value' => $excludeValue,
            'notify' => $notify,
            'audience' => $audience,
            'name' => $name,
            'hypothesis' => $hypothesis,
            'description' => $description,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'variants' => $variants,
            'traffic_share' => $trafficShare,
            'target_match' => $targetMatch,
            'target_value' => $targetValue,
        ];
    }

    /**
     * @param array<int, mixed> $variants
     * @param array<string, mixed>|null $existing the experiment as it is stored
     */
    private function checkCanSave(int $idSite, array $variants, ?array $existing = null): void
    {
        Piwik::checkUserHasWriteAccess($idSite);

        if (ExperimentValidator::hasCode(ExperimentValidator::normalizeVariants($variants)) || ($existing !== null && ExperimentValidator::hasCode($existing['variants']))) {
            Piwik::checkUserHasAdminAccess($idSite);
        }
    }
}
