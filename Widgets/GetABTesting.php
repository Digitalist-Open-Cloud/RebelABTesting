<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Widgets;

use Piwik\Date;
use Piwik\Plugins\RebelABTesting\Controller;
use Piwik\Widget\Widget;
use Piwik\Widget\WidgetConfig;

class GetABTesting extends Widget
{
    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId('RebelABTesting_RebelABTesting');
        $config->setSubcategoryId('RebelABTesting_CreateNewExperiment');
        $config->setName('RebelABTesting_CreateNewExperiment');
        $config->setOrder(97);
        $config->setIsEnabled(Controller::getAdminSite() > 0);
    }

    public function render()
    {
        $idSite = Controller::checkAdminSite();

        return $this->renderTemplate('index', Controller::getFormVariables($idSite) + [
            'experiment' => [
                'name' => '',
                'hypothesis' => '',
                'description' => '',
                'from_date' => Date::now()->toString(),
                'to_date' => Date::now()->addPeriod(1, 'month')->toString(),
                'traffic_share' => 100,
                'target_match' => 'any',
                'target_value' => '',
                'primary_goal' => null,
                'variants' => [['name' => '', 'css' => '', 'js' => '']],
            ],
            'actionUrl' => Controller::getActionUrl('addExperiment', $idSite),
        ]);
    }
}
