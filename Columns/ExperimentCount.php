<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Columns;

use Piwik\Plugin\Dimension\VisitDimension;
use Piwik\Plugins\RebelABTesting\Tracker\ExposureParams;
use Piwik\Tracker\Action;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visitor;

class ExperimentCount extends VisitDimension
{
    protected $columnName = 'abtest_idexperiment';
    protected $columnType = 'INT UNSIGNED DEFAULT NULL';
    protected $type = self::TYPE_NUMBER;
    protected $nameSingular = 'RebelABTesting_DimensionExperimentId';
    protected $category = 'RebelABTesting_RebelABTesting';
    protected $segmentName = 'experimentId';
    protected $acceptValues = 'The ID of an experiment, for instance 3';

    /**
     * @param Action|null $action
     * @return int|false
     */
    public function onNewVisit(Request $request, Visitor $visitor, $action)
    {
        $exposure = ExposureParams::fromRequestParams($request->getParams());

        return $exposure['idexperiment'] ?? false;
    }

    /**
     * @param Action|null $action
     * @return int|false
     */
    public function onExistingVisit(Request $request, Visitor $visitor, $action)
    {
        return $this->onNewVisit($request, $visitor, $action);
    }
}
