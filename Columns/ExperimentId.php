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

class ExperimentId extends Dimension
{
    protected $dbTableName = LogExperiment::TABLE;
    protected $columnName = 'idexperiment';
    protected $type = self::TYPE_NUMBER;
    protected $nameSingular = 'RebelABTesting_DimensionExperimentId';
    protected $category = 'RebelABTesting_RebelABTesting';
    protected $segmentName = 'experimentId';
    protected $acceptValues = 'The ID of an experiment, for instance 3';
}
