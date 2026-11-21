<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Tracker;

use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;
use Piwik\Tracker\Request;
use Piwik\Tracker\RequestProcessor as MatomoRequestProcessor;
use Piwik\Tracker\Visit\VisitProperties;

class RequestProcessor extends MatomoRequestProcessor
{
    /**
     * Logs the request for the experiment it names, when that experiment belongs to the website
     * and is running. What the browser sends is not trusted beyond that.
     */
    public function recordLogs(VisitProperties $visitProperties, Request $request)
    {
        $exposure = ExposureParams::fromRequestParams($request->getParams());
        if ($exposure === null || $exposure['variant'] === null) {
            return;
        }

        $idSite = (int) $request->getIdSite();
        $experiments = new Experiments();

        $experiment = $exposure['idexperiment'] === null ? null : $experiments->getById($exposure['idexperiment'], $idSite);
        if ($experiment === null && $exposure['experiment_name'] !== ExposureParams::UNKNOWN_NAME) {
            $id = $experiments->getIdByName($idSite, $exposure['experiment_name']);
            $experiment = $id === null ? null : $experiments->getById($id, $idSite);
        }

        if (!ExposureParams::isWithinSchedule((int) $request->getCurrentTimestamp(), $experiment)) {
            return;
        }

        (new LogExperiment())->createLog([
            'idsite' => $idSite,
            'experiment_name' => $experiment['name'],
            'idexperiment' => (int) $experiment['id'],
            'assignment_id' => $exposure['assignment_id'],
            'variant' => $exposure['variant'],
            'idvisit' => $visitProperties->getProperty('idvisit'),
            'idvisitor' => $visitProperties->getProperty('idvisitor'),
            'server_time' => gmdate('Y-m-d H:i:s', (int) $request->getCurrentTimestamp()),
            'created_time' => gmdate('Y-m-d H:i:s'),
            'category' => 'Experiment',
            'idaction_url' => $visitProperties->getProperty('visit_exit_idaction_url') ?? $visitProperties->getProperty('visit_entry_idaction_url'),
            'idaction_name' => $visitProperties->getProperty('visit_exit_idaction_name') ?? $visitProperties->getProperty('visit_entry_idaction_name'),
        ]);
    }
}
