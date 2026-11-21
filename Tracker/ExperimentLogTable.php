<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Tracker;

use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;
use Piwik\Tracker\LogTable;

/**
 * Tells Matomo about the log of the plugin, so that its rows are deleted with their visits and can
 * be used in segments.
 */
class ExperimentLogTable extends LogTable
{
    public function getName()
    {
        return LogExperiment::TABLE;
    }

    public function getIdColumn()
    {
        return 'idlog';
    }

    public function getPrimaryKey()
    {
        return ['idlog'];
    }

    public function getColumnToJoinOnIdVisit()
    {
        return 'idvisit';
    }

    public function getDateTimeColumn()
    {
        return 'server_time';
    }

    public function hasIdVisitorColumn(): bool
    {
        return true;
    }

    /**
     * A visit can have a row for each experiment, and must still count once in a segment.
     */
    public function shouldJoinWithSubSelect()
    {
        return true;
    }
}
