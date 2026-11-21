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

class IsExperiment extends VisitDimension
{
    protected $columnName = 'abtest_variant';
    protected $columnType = 'INT UNSIGNED DEFAULT NULL';
    protected $type = self::TYPE_NUMBER;
    protected $nameSingular = 'RebelABTesting_DimensionVariant';
    protected $category = 'RebelABTesting_RebelABTesting';
    protected $segmentName = 'experimentVariant';
    protected $acceptValues = '1 for the original, 2 for the variant';

    /**
     * @param Action|null $action
     * @return int|false
     */
    public function onNewVisit(Request $request, Visitor $visitor, $action)
    {
        $exposure = ExposureParams::fromRequestParams($request->getParams());

        return $exposure['variant'] ?? false;
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
