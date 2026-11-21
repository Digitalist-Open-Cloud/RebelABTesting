<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Piwik\Common;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Plugin\Archiver as MatomoArchiver;
use Piwik\Db;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;

class Archiver extends MatomoArchiver
{
    public const RECORD_NAME = 'RebelABTesting_ExperimentData';
    public const RECORD_NAME_UNIQUE_VISITORS = 'RebelABTesting_ExperimentUniqueVisitors';
    public const RECORD_NAME_GOALS = 'RebelABTesting_ExperimentGoals';
    public const DIMENSION = 'experiment_name';

    public function aggregateDayReport()
    {

        $params = $this->getProcessor()->getParams();
        $idSite = $params->getSite()->getId();
        $dateStart = $params->getDateStart()->toString('Y-m-d 00:00:00');
        $dateEnd = $params->getDateEnd()->toString('Y-m-d 23:59:59');

        // The log has a row per tracking request, so visits and visitors are counted distinct.
        $visitsQuery = "
            SELECT
                experiment_name AS label,
                variant AS variant,
                COUNT(DISTINCT idvisit) AS nb_visits
            FROM " . Common::prefixTable(LogExperiment::TABLE) . "
            WHERE idsite = ?
            AND server_time BETWEEN ? AND ?
            GROUP BY experiment_name, variant
        ";
        $visitRows = Db::fetchAll($visitsQuery, [$idSite, $dateStart, $dateEnd]);
        $this->getProcessor()->insertBlobRecord(
            self::RECORD_NAME,
            $this->buildOneLevelTable($visitRows, 'variant', ['nb_visits'])->getSerialized()
        );

        $uniqueVisitorsQuery = "
            SELECT
                experiment_name AS label,
                variant AS variant,
                COUNT(DISTINCT idvisitor) AS nb_unique_visitors
            FROM " . Common::prefixTable(LogExperiment::TABLE) . "
            WHERE idsite = ?
            AND server_time BETWEEN ? AND ?
            GROUP BY experiment_name, variant
        ";
        $uniqueVisitorRows = Db::fetchAll($uniqueVisitorsQuery, [$idSite, $dateStart, $dateEnd]);
        $this->getProcessor()->insertBlobRecord(
            self::RECORD_NAME_UNIQUE_VISITORS,
            $this->buildOneLevelTable($uniqueVisitorRows, 'variant', ['nb_unique_visitors'])->getSerialized()
        );

        // Only conversions from the moment the visit was in the experiment count.
        $goalsQuery = "
            SELECT
                v.experiment_name AS label,
                v.variant AS variant,
                c.idgoal AS idgoal,
                COUNT(DISTINCT c.idvisit) AS nb_visits_converted,
                COUNT(*) AS nb_conversions
            FROM " . Common::prefixTable(LogExperiment::TABLE) . " v
            INNER JOIN " . Common::prefixTable('log_conversion') . " c
                ON c.idvisit = v.idvisit AND c.idsite = v.idsite AND c.server_time >= v.server_time
            WHERE v.idsite = ?
            AND v.server_time BETWEEN ? AND ?
            GROUP BY v.experiment_name, v.variant, c.idgoal
        ";
        $goalRows = Db::fetchAll($goalsQuery, [$idSite, $dateStart, $dateEnd]);
        $this->getProcessor()->insertBlobRecord(
            self::RECORD_NAME_GOALS,
            $this->buildTwoLevelTable($goalRows, 'variant', 'idgoal', ['nb_visits_converted', 'nb_conversions'])->getSerialized()
        );
    }

    /**
     * A row per label with the sum of its subtable, which has a row per value of the identity column.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $metricColumns
     */
    private function buildOneLevelTable(array $rows, string $identityColumn, array $metricColumns): DataTable
    {
        $grouped = RowGrouper::groupByOneLevel($rows, $identityColumn, $metricColumns);
        $table = new DataTable();
        foreach ($grouped as $label => $identityRows) {
            $subtable = new DataTable();
            $totals = self::zeroedMetrics($metricColumns);
            foreach ($identityRows as $identityValue => $metrics) {
                $subtable->addRowFromSimpleArray(array_merge(['label' => $identityValue], $metrics));
                $totals = self::addMetrics($totals, $metrics);
            }
            $topRow = new Row([Row::COLUMNS => array_merge(['label' => $label], $totals)]);
            $topRow->setSubtable($subtable);
            $table->addRow($topRow);
        }
        return $table;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $metricColumns
     */
    private function buildTwoLevelTable(array $rows, string $outerIdentityColumn, string $innerIdentityColumn, array $metricColumns): DataTable
    {
        $grouped = RowGrouper::groupByTwoLevels($rows, $outerIdentityColumn, $innerIdentityColumn, $metricColumns);
        $table = new DataTable();
        foreach ($grouped as $label => $outerRows) {
            $outerTable = new DataTable();
            $topTotals = self::zeroedMetrics($metricColumns);
            foreach ($outerRows as $outerValue => $innerRows) {
                $innerTable = new DataTable();
                $outerTotals = self::zeroedMetrics($metricColumns);
                foreach ($innerRows as $innerValue => $metrics) {
                    $innerTable->addRowFromSimpleArray(array_merge(['label' => $innerValue], $metrics));
                    $outerTotals = self::addMetrics($outerTotals, $metrics);
                }
                $outerRow = new Row([Row::COLUMNS => array_merge(['label' => $outerValue], $outerTotals)]);
                $outerRow->setSubtable($innerTable);
                $outerTable->addRow($outerRow);
                $topTotals = self::addMetrics($topTotals, $outerTotals);
            }
            $topRow = new Row([Row::COLUMNS => array_merge(['label' => $label], $topTotals)]);
            $topRow->setSubtable($outerTable);
            $table->addRow($topRow);
        }
        return $table;
    }

    /**
     * @param string[] $metricColumns
     * @return array<string, int>
     */
    private static function zeroedMetrics(array $metricColumns): array
    {
        return array_fill_keys($metricColumns, 0);
    }

    /**
     * @param array<string, int> $totals
     * @param array<string, mixed> $metrics
     * @return array<string, int>
     */
    private static function addMetrics(array $totals, array $metrics): array
    {
        foreach ($totals as $column => $value) {
            $totals[$column] = $value + ($metrics[$column] ?? 0);
        }
        return $totals;
    }

    /**
     * The records that can be added up over days. Unique visitors cannot: a visitor of three days
     * would count three times.
     *
     * A visit that crosses midnight is logged on both days and counts once for each.
     *
     * @return string[]
     */
    public static function recordNamesForMultiPeriod(): array
    {
        return [self::RECORD_NAME, self::RECORD_NAME_GOALS];
    }

    public function aggregateMultipleReports()
    {
        $this->getProcessor()->aggregateDataTableRecords(self::recordNamesForMultiPeriod());
    }
}
