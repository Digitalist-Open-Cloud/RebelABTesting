<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Reports;

use Piwik\Piwik;
use Piwik\Plugin\ViewDataTable;
use Piwik\Plugins\RebelABTesting\Columns\ExperimentName;

/**
 * Visits by experiment, then variant.
 */
class GetExperimentReport extends Base
{
    protected function init()
    {
        parent::init();
        $this->name = Piwik::translate('RebelABTesting_ExperimentsReport');
        $this->dimension = new ExperimentName();
        $this->metrics = [
            'nb_visits' => Piwik::translate('RebelABTesting_NbVisits'),
        ];
        $this->processedMetrics = [];
        $this->order = 1;
        $this->subcategoryId = $this->name;
        $this->documentation = Piwik::translate('RebelABTesting_ReportHelpText');
    }

    public function configureView(ViewDataTable $view)
    {
        $view->config->show_table = true;
        $view->config->columns_to_display = ['label', 'nb_visits'];
        $view->config->addTranslation('label', Piwik::translate('RebelABTesting_ExperimentName'));
        $view->config->show_footer_message = true;
    }
}
