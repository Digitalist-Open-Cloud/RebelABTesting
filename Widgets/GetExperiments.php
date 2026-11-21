<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Widgets;

use Piwik\Nonce;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Controller;
use Piwik\Widget\Widget;
use Piwik\Widget\WidgetConfig;

class GetExperiments extends Widget
{
    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId('RebelABTesting_RebelABTesting');
        $config->setSubcategoryId('RebelABTesting_ExistingExperiments');
        $config->setName('RebelABTesting_ExistingExperiments');
        $config->setOrder(98);
        $config->setIsEnabled(Controller::getAdminSite() > 0);
    }

    public function render()
    {
        $idSite = Controller::checkAdminSite();

        return $this->renderTemplate('experiments', [
            'experiments' => (new Experiments())->getAllForSite($idSite),
            'deleteUrl' => Controller::getActionUrl('delete', $idSite),
            'editUrl' => Controller::getActionUrl('editExperimentForm', $idSite),
            'nonce' => Nonce::getNonce(Controller::NONCE),
        ]);
    }
}
