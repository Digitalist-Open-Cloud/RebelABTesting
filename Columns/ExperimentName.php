<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Columns;

use Piwik\Columns\Dimension;

/**
 * The first column of the reports.
 */
class ExperimentName extends Dimension
{
    protected $nameSingular = 'RebelABTesting_ExperimentName';
}
