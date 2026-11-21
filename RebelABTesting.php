<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Piwik\Common;
use Piwik\Plugin;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;

class RebelABTesting extends Plugin
{
    public function registerEvents()
    {
        return [
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
            'Db.getTablesInstalled' => 'getTablesInstalled',
        ];
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = 'plugins/RebelABTesting/stylesheets/menuIcon.less';
        $stylesheets[] = 'plugins/RebelABTesting/stylesheets/rebelABTesting.less';
    }

    public function getTablesInstalled(&$tables)
    {
        $tables[] = Common::prefixTable(Experiments::TABLE);
        $tables[] = Common::prefixTable(LogExperiment::TABLE);
    }

    public function isTrackerPlugin()
    {
        return true;
    }

    public function install()
    {
        (new Experiments())->install();
        (new LogExperiment())->install();
    }

    public function uninstall()
    {
        (new Experiments())->uninstall();
        (new LogExperiment())->uninstall();
    }
}
