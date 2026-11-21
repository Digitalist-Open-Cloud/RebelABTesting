<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Exception;
use Piwik\Archive;
use Piwik\DataTable;
use Piwik\Piwik;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;

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
     * Adds an experiment.
     *
     * @param string $fromDate the first day, as YYYY-MM-DD in UTC
     * @param string $toDate the last day, as YYYY-MM-DD in UTC
     * @param array $variants one or more variants, each with `name`, `css` and `js`
     * @param int $trafficShare the percentage of visitors that take part, split evenly between the
     *                          original and the variants
     * @param string $targetMatch how the address of a page is compared with $targetValue: any,
     *                            equals, contains, starts_with or regexp
     * @return int the ID of the experiment
     */
    public function insertExperiment(int $idSite, string $name, string $hypothesis, string $description, string $fromDate, string $toDate, array $variants, int $trafficShare = 100, string $targetMatch = 'any', string $targetValue = ''): int
    {
        Piwik::checkUserHasAdminAccess($idSite);

        return $this->experiments->insertExperiment($idSite, self::toFields($name, $hypothesis, $description, $fromDate, $toDate, $variants, $trafficShare, $targetMatch, $targetValue));
    }

    /**
     * @param array $variants one or more variants, each with `name`, `css` and `js`
     */
    public function updateExperiment(int $id, int $idSite, string $name, string $hypothesis, string $description, string $fromDate, string $toDate, array $variants, int $trafficShare = 100, string $targetMatch = 'any', string $targetValue = ''): void
    {
        Piwik::checkUserHasAdminAccess($idSite);
        $this->experiments->updateExperiment($id, $idSite, self::toFields($name, $hypothesis, $description, $fromDate, $toDate, $variants, $trafficShare, $targetMatch, $targetValue));
    }

    /**
     * Ends an experiment now.
     */
    public function stopExperiment(int $id, int $idSite): void
    {
        Piwik::checkUserHasAdminAccess($idSite);
        $this->experiments->stopExperiment($id, $idSite);
    }

    public function deleteExperiment(int $id, int $idSite): void
    {
        Piwik::checkUserHasAdminAccess($idSite);
        $this->experiments->deleteExperiment($id, $idSite);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExperiment(int $id, int $idSite): ?array
    {
        Piwik::checkUserHasAdminAccess($idSite);

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
            return array_intersect_key($experiment, array_flip(['id', 'idsite', 'name', 'from_date', 'to_date', 'status']));
        }, $this->experiments->getAllForSite($idSite));
    }

    /**
     * Visits per variant, and for each goal the conversions, the conversion rate, the difference to
     * the original and how likely the variant is to be better than the original.
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
     * Visits per experiment. The subtable of an experiment has its variants.
     *
     * @param string|null $segment
     * @param bool|int $idSubtable
     */
    public function getExperimentData(int $idSite, string $period, string $date, $segment = null, $idSubtable = false): DataTable
    {
        Piwik::checkUserHasViewAccess($idSite);

        return Archive::createDataTableFromArchive(Archiver::RECORD_NAME, $idSite, $period, $date, $segment, false, false, $idSubtable);
    }

    /**
     * Conversions per experiment. The subtable of an experiment has its variants, and the subtable of
     * a variant its goals.
     *
     * @param string|null $segment
     * @param bool|int $idSubtable
     */
    public function getExperimentGoalData(int $idSite, string $period, string $date, $segment = null, $idSubtable = false): DataTable
    {
        Piwik::checkUserHasViewAccess($idSite);

        return Archive::createDataTableFromArchive(Archiver::RECORD_NAME_GOALS, $idSite, $period, $date, $segment, false, false, $idSubtable);
    }

    /**
     * Unique visitors per experiment and variant. There is only data for a day, as visitors of
     * several days cannot be added up.
     *
     * @param string|null $segment
     */
    public function getExperimentUniqueVisitorData(int $idSite, string $period, string $date, $segment = null): DataTable
    {
        Piwik::checkUserHasViewAccess($idSite);

        return Archive::createDataTableFromArchive(Archiver::RECORD_NAME_UNIQUE_VISITORS, $idSite, $period, $date, $segment);
    }

    /**
     * @param array<int, mixed> $variants
     * @return array<string, mixed>
     */
    private static function toFields(string $name, string $hypothesis, string $description, string $fromDate, string $toDate, array $variants, int $trafficShare, string $targetMatch, string $targetValue): array
    {
        return [
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
}
