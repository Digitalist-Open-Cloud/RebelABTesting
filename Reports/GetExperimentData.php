<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Reports;

use Piwik\Piwik;
use Piwik\Plugins\RebelABTesting\Columns\ExperimentName;

/**
 * The metadata of the API method the experiments report reads. Matomo needs it to open the row of
 * an experiment and to flatten the report. It has no page of its own.
 */
class GetExperimentData extends Base
{
    protected function init()
    {
        parent::init();
        $this->name = Piwik::translate('RebelABTesting_ExperimentsReport');
        $this->dimension = new ExperimentName();
        $this->metrics = [
            'nb_visits' => Piwik::translate('RebelABTesting_NbVisits'),
        ];
        $this->actionToLoadSubTables = $this->action;
    }
}
