<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Exception;
use Piwik\Piwik;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Validation\ExperimentValidator;

/**
 * Experiments, which show variants of a page to a share of the visitors, and how the variants did.
 *
 * @method static \Piwik\Plugins\RebelABTesting\API getInstance()
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
     * @param string $kind `ab` for a test of variants, `fake_door` for a button to something that
     *                     does not exist yet, to see how many visitors want it, `watch` to count the
     *                     visits that see and click an element the page already has
     * @param array $door the fake door: `selector` of the element it is put next to, `position`
     *                    (after, before, append or prepend), `element` (button or link), `label`,
     *                    `css_class` and the `message` shown after a click. For `watch`: the `selector` of the
     *                    element to watch
     * @param array $guardrails up to two measures that must not get worse
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
        array $guardrails = []
    ): int {
        $this->checkCanSave($idSite, $variants);

        return $this->experiments->insertExperiment($idSite, self::toFields($idSite, $name, $hypothesis, $description, $fromDate, $toDate, $variants, $trafficShare, $targetMatch, $targetValue, $primaryMeasure, $kind, $door, $guardrails));
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
        array $guardrails = []
    ): void {
        $this->checkCanSave($idSite, $variants, $this->experiments->getById($id, $idSite));
        $this->experiments->updateExperiment($id, $idSite, self::toFields($idSite, $name, $hypothesis, $description, $fromDate, $toDate, $variants, $trafficShare, $targetMatch, $targetValue, $primaryMeasure, $kind, $door, $guardrails));
    }

    /**
     * Ends an experiment now.
     */
    public function stopExperiment(int $id, int $idSite): void
    {
        Piwik::checkUserHasWriteAccess($idSite);
        $this->experiments->stopExperiment($id, $idSite);
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
     * The experiments of a website, without what their variants do.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExperiments(int $idSite): array
    {
        Piwik::checkUserHasViewAccess($idSite);

        return array_map(function (array $experiment): array {
            return array_intersect_key($experiment, array_flip(['id', 'idsite', 'name', 'kind', 'from_date', 'to_date', 'status']))
                + ['has_code' => ExperimentValidator::hasCode($experiment['variants'])];
        }, $this->experiments->getAllForSite($idSite));
    }

    /**
     * Visits and visitors per variant, whether the visitors are split evenly, and for each measure
     * the value per variant, the difference to the original and how likely the variant is to be
     * better than the original. For a fake door: the visits it was shown to and their clicks.
     *
     * @return array<string, mixed>
     */
    public function getExperimentResult(int $idSite, int $id): array
    {
        Piwik::checkUserHasViewAccess($idSite);

        $experiment = $this->experiments->getById($id, $idSite);
        if ($experiment === null) {
            throw new Exception(Piwik::translate('RebelABTesting_ExperimentNotFound'));
        }

        return (new Results())->get($experiment);
    }

    /**
     * @param array<int, mixed> $variants
     * @param array<string, mixed> $primaryMeasure
     * @param array<string, mixed> $door
     * @param array<int, mixed> $guardrails
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
        array $guardrails
    ): array {
        return [
            'kind' => $kind,
            'door' => $door,
            'primary_measure' => Measures::normalize($idSite, $primaryMeasure),
            'guardrails' => Measures::normalizeList($idSite, $guardrails),
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
