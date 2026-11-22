<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Dao;

use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;

/**
 * The visits that took part in an experiment, a row for each visit and experiment.
 */
class LogExperiment
{
    public const TABLE = 'rebel_ab_testing_log';

    public function install()
    {
        DbHelper::createTable(self::TABLE, "
            `idlog` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `idsite` INT UNSIGNED NOT NULL,
            `idvisit` BIGINT UNSIGNED NOT NULL,
            `idvisitor` BINARY(8) NOT NULL,
            `idexperiment` INT UNSIGNED NOT NULL,
            `experiment_name` VARCHAR(255) NOT NULL,
            `variant` TINYINT UNSIGNED NOT NULL,
            `assignment_id` CHAR(32) NOT NULL,
            `server_time` DATETIME NOT NULL,
            `clicked` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`idlog`),
            UNIQUE KEY `visit_experiment` (`idvisit`, `idexperiment`),
            KEY `site_time` (`idsite`, `server_time`),
            KEY `experiment_variant` (`idexperiment`, `variant`)
        ");
    }

    public function uninstall()
    {
        Db::dropTables(Common::prefixTable(self::TABLE));
    }

    /**
     * A visit keeps the variant it was first logged with.
     *
     * @param array<string, mixed> $row the values by column name
     */
    public function createLog(array $row)
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT IGNORE INTO %s (%s) VALUES (%s)',
            Common::prefixTable(self::TABLE),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        Db::query($sql, array_values($row));
    }

    /**
     * Notes that the visit clicked the fake door of the experiment.
     *
     * @param int|string $idVisit
     */
    public function markClicked($idVisit, int $idExperiment)
    {
        Db::query('UPDATE ' . Common::prefixTable(self::TABLE) . ' SET clicked = 1 WHERE idvisit = ? AND idexperiment = ?', [$idVisit, $idExperiment]);
    }
}
