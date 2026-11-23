<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelExperiments\Columns;

use Piwik\Columns\Dimension;
use Piwik\Plugins\RebelExperiments\Dao\LogExperiment;

class ExperimentId extends Dimension
{
    protected $dbTableName = LogExperiment::TABLE;
    protected $columnName = 'idexperiment';
    protected $type = self::TYPE_NUMBER;
    protected $nameSingular = 'RebelExperiments_DimensionExperimentId';
    protected $category = 'RebelExperiments_RebelExperiments';
    protected $segmentName = 'experimentId';
    protected $acceptValues = 'The ID of an experiment, for instance 3';
}
