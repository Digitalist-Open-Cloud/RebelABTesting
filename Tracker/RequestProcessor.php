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
use Piwik\Plugins\RebelABTesting\Validation\ExperimentValidator;
use Piwik\Tracker\Request;
use Piwik\Tracker\RequestProcessor as MatomoRequestProcessor;
use Piwik\Tracker\Visit\VisitProperties;

class RequestProcessor extends MatomoRequestProcessor
{
    /**
     * Logs the visit for each experiment the request names, when that experiment belongs to the
     * website, is running and has the variant. A fake door and a watched element only count for
     * the visits they were shown to.
     */
    public function recordLogs(VisitProperties $visitProperties, Request $request)
    {
        $assignments = Assignments::fromRequestParams($request->getParams());
        if (empty($assignments)) {
            return;
        }

        $idSite = (int) $request->getIdSite();
        $timestamp = (int) $request->getCurrentTimestamp();
        $experiments = new Experiments();
        $log = new LogExperiment();
        $door = Assignments::getDoorSignal($request->getParams());

        // ponytail: one query per experiment and request, keep them in the tracker cache if that shows up in profiles
        foreach ($assignments as $idExperiment => $assignment) {
            $experiment = $experiments->getById($idExperiment, $idSite);

            if (
                $experiment === null
                || Experiments::getStatus($experiment, $timestamp) !== Experiments::STATUS_RUNNING
                || $assignment['variant'] > Experiments::getMaxVariant($experiment)
            ) {
                continue;
            }

            $isDoor = ExperimentValidator::countsWhatIsShown($experiment['kind']);
            if ($isDoor && ($door === null || $door['idexperiment'] !== $idExperiment || $assignment['variant'] !== 2)) {
                continue;
            }

            $log->createLog([
                'idsite' => $idSite,
                'idvisit' => $visitProperties->getProperty('idvisit'),
                'idvisitor' => $visitProperties->getProperty('idvisitor'),
                'idexperiment' => $idExperiment,
                'experiment_name' => $experiment['name'],
                'variant' => $assignment['variant'],
                'assignment_id' => $assignment['assignment_id'],
                'server_time' => gmdate('Y-m-d H:i:s', $timestamp),
            ]);

            if ($isDoor && $door['signal'] === Assignments::DOOR_CLICKED) {
                $log->markClicked($visitProperties->getProperty('idvisit'), $idExperiment);
            }
        }
    }
}
