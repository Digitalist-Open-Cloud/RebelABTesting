<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Widgets;

use Piwik\Plugins\RebelABTesting\Controller;
use Piwik\Widget\Widget;
use Piwik\Widget\WidgetConfig;

class GetInsertExperiment extends Widget
{
    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId('RebelABTesting_RebelABTesting');
        $config->setSubcategoryId('RebelABTesting_InsertExperiment');
        $config->setName('RebelABTesting_InsertExperiment');
        $config->setOrder(99);
        $config->setIsEnabled(Controller::getAdminSite() > 0);
    }

    public function render()
    {
        Controller::checkAdminSite();

        return $this->renderTemplate('insert');
    }
}
