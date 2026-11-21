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
use Piwik\Plugins\RebelABTesting\Takeover;

class LogExperiment
{
    public const TABLE = 'rebel_ab_testing_log';

    public function install()
    {
        if (Takeover::renameTable('simple_ab_testing_log', self::TABLE)) {
            return;
        }

        DbHelper::createTable(self::TABLE, "
            `idlog` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `idsite` INT UNSIGNED NOT NULL,
            `idvisit` BIGINT UNSIGNED NOT NULL,
            `idvisitor` BINARY(8) NOT NULL,
            `count` INT DEFAULT NULL,
            `experiment_name` VARCHAR(255) NULL,
            `variant` INT DEFAULT NULL,
            `server_time` DATETIME NOT NULL,
            `created_time` DATETIME NOT NULL,
            `idaction_url` INTEGER UNSIGNED NULL,
            `idaction_name` INTEGER UNSIGNED NULL,
            `idgoal` INT DEFAULT NULL,
            `category` VARCHAR(255) NOT NULL DEFAULT '',
            `idexperiment` INT NULL,
            `assignment_id` CHAR(32) NULL,
            PRIMARY KEY (`idlog`),
            KEY `idx_visit_time` (idvisit, server_time)
        ");
    }

    public function uninstall()
    {
        Db::dropTables(Common::prefixTable(self::TABLE));
    }

    /**
     * @param array<string, mixed> $row the values by column name
     */
    public function createLog(array $row)
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            Common::prefixTable(self::TABLE),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        Db::query($sql, array_values($row));
    }
}
