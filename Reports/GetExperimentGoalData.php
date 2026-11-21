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
 * Conversions by experiment, then variant, then goal.
 */
class GetExperimentGoalData extends Base
{
    protected function init()
    {
        parent::init();
        $this->name = Piwik::translate('RebelABTesting_GoalsReport');
        $this->dimension = new ExperimentName();
        $this->metrics = [
            'nb_visits_converted' => Piwik::translate('RebelABTesting_NbVisitsConverted'),
            'nb_conversions' => Piwik::translate('RebelABTesting_NbConversions'),
        ];
        $this->processedMetrics = [];
        $this->order = 2;
        $this->subcategoryId = $this->name;
        $this->documentation = Piwik::translate('RebelABTesting_GoalsReportHelpText');
        $this->actionToLoadSubTables = $this->action;
    }

    public function configureView(ViewDataTable $view)
    {
        $view->config->show_table = true;
        $view->config->columns_to_display = ['label', 'nb_visits_converted', 'nb_conversions'];
        $view->config->addTranslation('label', Piwik::translate('RebelABTesting_ExperimentName'));
        $view->config->show_footer_message = true;
    }
}
