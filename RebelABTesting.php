<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Piwik\Category\Subcategory;
use Piwik\Common;
use Piwik\Piwik;
use Piwik\Plugin;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;
use Piwik\Request;
use Piwik\Widget\WidgetConfig;

class RebelABTesting extends Plugin
{
    private const CATEGORY = 'RebelABTesting_RebelABTesting';

    public function registerEvents()
    {
        return [
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
            'AssetManager.getJavaScriptFiles' => 'getJavaScriptFiles',
            'Db.getTablesInstalled' => 'getTablesInstalled',
            'Category.addSubcategories' => 'addSubcategories',
            'Widget.addWidgetConfigs' => 'addWidgetConfigs',
        ];
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = 'plugins/RebelABTesting/stylesheets/menuIcon.less';
        $stylesheets[] = 'plugins/RebelABTesting/stylesheets/rebelABTesting.less';
    }

    public function getJavaScriptFiles(&$files)
    {
        $files[] = 'plugins/RebelABTesting/javascripts/experimentForm.js';
        $files[] = 'plugins/RebelABTesting/javascripts/evolutionChart.js';
    }

    public function getTablesInstalled(&$tables)
    {
        $tables[] = Common::prefixTable(Experiments::TABLE);
        $tables[] = Common::prefixTable(LogExperiment::TABLE);
    }

    /**
     * Each experiment has a page with its result in the reporting menu.
     */
    public function addSubcategories(&$subcategories)
    {
        foreach ($this->getExperimentsOfRequestedSite() as $order => $experiment) {
            $subcategory = new Subcategory();
            $subcategory->setCategoryId(self::CATEGORY);
            $subcategory->setId((string) $experiment['id']);
            $subcategory->setName($experiment['name']);
            $subcategory->setOrder(100 + $order);
            $subcategories[] = $subcategory;
        }
    }

    public function addWidgetConfigs(&$configs)
    {
        foreach ($this->getExperimentsOfRequestedSite() as $experiment) {
            $config = new WidgetConfig();
            $config->setCategoryId(self::CATEGORY);
            $config->setSubcategoryId((string) $experiment['id']);
            $config->setName($experiment['name']);
            $config->setModule('RebelABTesting');
            $config->setAction('result');
            $config->setParameters(['idExperiment' => $experiment['id']]);
            $config->setIsNotWidgetizable();
            $configs[] = $config;
        }
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getExperimentsOfRequestedSite(): array
    {
        $idSite = Request::fromRequest()->getIntegerParameter('idSite', 0);

        return $idSite > 0 && Piwik::isUserHasViewAccess($idSite) ? (new Experiments())->getAllForSite($idSite) : [];
    }
}
