<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Columns;

use Piwik\Columns\Dimension;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;

class ExperimentVariant extends Dimension
{
    protected $dbTableName = LogExperiment::TABLE;
    protected $columnName = 'variant';
    protected $type = self::TYPE_NUMBER;
    protected $nameSingular = 'RebelABTesting_DimensionVariant';
    protected $category = 'RebelABTesting_RebelABTesting';
    protected $segmentName = 'experimentVariant';
    protected $acceptValues = '1 for the original, 2 for the first variant, 3 for the second';
}
