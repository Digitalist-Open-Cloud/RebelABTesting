<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Widgets;

use Piwik\Nonce;
use Piwik\Piwik;
use Piwik\Plugins\RebelABTesting\API;
use Piwik\Request;
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
        $config->setOrder(1);
    }

    public function render()
    {
        $idSite = Request::fromRequest()->getIntegerParameter('idSite', 0);
        Piwik::checkUserHasViewAccess($idSite);
        $isAdmin = Piwik::isUserHasAdminAccess($idSite);

        return $this->renderTemplate('experiments', [
            'experiments' => API::getInstance()->getExperiments($idSite),
            'isAdmin' => $isAdmin,
            'stopUrl' => Controller::getActionUrl('stop', $idSite),
            'deleteUrl' => Controller::getActionUrl('delete', $idSite),
            'editUrl' => Controller::getActionUrl('editExperimentForm', $idSite),
            'nonce' => $isAdmin ? Nonce::getNonce(Controller::NONCE) : '',
        ]);
    }
}
