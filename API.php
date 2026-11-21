<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Piwik\Archive;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\Db;
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Piwik;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;

/**
 * Experiments, which show a variant of a page to half of the visitors, and their reports.
 *
 * @method static \Piwik\Plugins\RebelABTesting\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    /**
     * The CSS and JavaScript of an experiment have to arrive as they were written.
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
     * Adds an experiment. Only one experiment can run on a website at a time.
     *
     * @param string $fromDate the first day, as YYYY-MM-DD in UTC
     * @param string $toDate the last day, as YYYY-MM-DD in UTC
     * @param string $cssInsert CSS added to the page for the variant
     * @param string $customJs JavaScript run on the page for the variant
     * @return int the ID of the experiment
     */
    public function insertExperiment(int $idSite, string $name, string $hypothesis, string $description, string $fromDate, string $toDate, string $cssInsert, string $customJs): int
    {
        Piwik::checkUserHasAdminAccess($idSite);

        return $this->experiments->insertExperiment($idSite, $name, $hypothesis, $description, $fromDate, $toDate, $cssInsert, $customJs);
    }

    public function updateExperiment(int $id, int $idSite, string $name, string $hypothesis, string $description, string $fromDate, string $toDate, string $cssInsert, string $customJs): void
    {
        Piwik::checkUserHasAdminAccess($idSite);
        $this->experiments->updateExperiment($id, $idSite, $name, $hypothesis, $description, $fromDate, $toDate, $cssInsert, $customJs);
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
     * What the tag on the website gets to know about an experiment.
     *
     * @return array<string, mixed>|null
     */
    public function getExperimentPublic(int $id, int $idSite): ?array
    {
        Piwik::checkUserHasViewAccess($idSite);
        $row = $this->experiments->getById($id, $idSite);

        return $row ? self::publicFields($row) : null;
    }

    /**
     * @ignore
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function publicFields(array $row): array
    {
        return [
            'found' => true,
            'name' => $row['name'],
            'from_date' => $row['from_date'],
            'to_date' => $row['to_date'],
            'css_insert' => $row['css_insert'],
            'js_insert' => $row['js_insert'],
        ];
    }

    /**
     * Visits and visitors per experiment and variant, read from the log and not from the archive.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExperimentReportData(int $idSite, string $period, string $date): array
    {
        Piwik::checkUserHasViewAccess($idSite);
        $periodObj = PeriodFactory::build($period, $date);

        $sql = '
            SELECT
                experiment_name AS `experiment_name`,
                variant AS `variant`,
                COUNT(DISTINCT idvisitor) AS `nb_unique_visitors`,
                COUNT(DISTINCT idvisit) AS `nb_visits`
            FROM ' . Common::prefixTable(LogExperiment::TABLE) . '
            WHERE idsite = ?
            AND server_time BETWEEN ? AND ?
            GROUP BY experiment_name, variant
        ';

        return Db::fetchAll($sql, [
            $idSite,
            $periodObj->getDateStart()->toString('Y-m-d 00:00:00'),
            $periodObj->getDateEnd()->toString('Y-m-d 23:59:59'),
        ]);
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
}
