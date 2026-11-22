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
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Results;
use Piwik\Plugins\RebelABTesting\Validation\ExperimentValidator;
use Piwik\Request;
use Piwik\Plugins\RebelABTesting\Controller;
use Piwik\Widget\Widget;
use Piwik\Widget\WidgetConfig;

class GetExperiments extends Widget
{
    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId('RebelABTesting_RebelABTesting');
        $config->setSubcategoryId('RebelABTesting_ManageExperiments');
        $config->setName('RebelABTesting_ManageExperiments');
        $config->setOrder(1);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getExperimentsWithSummary(int $idSite): array
    {
        $results = new Results();
        $experiments = [];
        // ponytail: the result of each experiment is worked out for the list, page the list if a website gets hundreds
        foreach ((new Experiments())->getAllForSite($idSite) as $experiment) {
            $experiments[] = [
                'id' => $experiment['id'],
                'name' => $experiment['name'],
                'kind' => $experiment['kind'],
                'status' => $experiment['status'],
                'from_date' => $experiment['from_date'],
                'to_date' => $experiment['to_date'],
                'decision' => $experiment['decision'],
                'has_code' => ExperimentValidator::hasCode($experiment['variants']),
                'summary' => $experiment['status'] === Experiments::STATUS_PLANNED ? null : $results->getSummary($experiment),
            ];
        }

        return $experiments;
    }

    public function render()
    {
        $idSite = Request::fromRequest()->getIntegerParameter('idSite', 0);
        Piwik::checkUserHasViewAccess($idSite);
        $canWrite = Piwik::isUserHasWriteAccess($idSite);

        return $this->renderTemplate('experiments', [
            'experiments' => $this->getExperimentsWithSummary($idSite),
            'canWrite' => $canWrite,
            'resultUrl' => Controller::getReportingUrl($idSite, ''),
            'canUseCode' => Piwik::isUserHasAdminAccess($idSite),
            'stopUrl' => Controller::getActionUrl('stop', $idSite),
            'deleteUrl' => Controller::getActionUrl('delete', $idSite),
            'editUrl' => Controller::getActionUrl('editExperimentForm', $idSite),
            'nonce' => $canWrite ? Nonce::getNonce(Controller::NONCE) : '',
        ]);
    }
}
