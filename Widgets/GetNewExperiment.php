<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelExperiments\Widgets;

use Piwik\Date;
use Piwik\Plugins\RebelExperiments\Controller;
use Piwik\Widget\Widget;
use Piwik\Widget\WidgetConfig;

class GetNewExperiment extends Widget
{
    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId('RebelExperiments_RebelExperiments');
        $config->setSubcategoryId('RebelExperiments_CreateNewExperiment');
        $config->setName('RebelExperiments_CreateNewExperiment');
        $config->setOrder(97);
        $config->setIsEnabled(Controller::getWriteSite() > 0);
    }

    public function render()
    {
        $idSite = Controller::checkWriteSite();

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
                'exclude_match' => 'none',
                'exclude_value' => '',
                'confidence' => 95,
                'notify' => true,
                'audience' => ['visitor' => '', 'devices' => [], 'campaign' => '', 'referrer' => '', 'languages' => []],
                'primary_measure' => null,
                'guardrails' => [],
                'kind' => 'ab',
                'door' => ['selector' => '', 'position' => 'after', 'element' => 'button', 'label' => '', 'css_class' => '', 'message' => ''],
                'variants' => [['name' => '', 'changes' => [], 'redirect' => '', 'css' => '', 'js' => '']],
            ],
            'actionUrl' => Controller::getActionUrl('addExperiment', $idSite),
        ]);
    }
}
