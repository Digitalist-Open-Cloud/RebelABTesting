<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelExperiments\Categories;

use Piwik\Category\Category;

class RebelExperimentsCategory extends Category
{
    protected $id = 'RebelExperiments_RebelExperiments';
    protected $order = 99;
    protected $icon = 'rebelExperimentsIcon';
}
