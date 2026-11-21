<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Categories;

use Piwik\Category\Category;

class RebelABTestingCategory extends Category
{
    protected $id = 'RebelABTesting_RebelABTesting';
    protected $order = 99;
    protected $icon = 'rebelABTestingIcon';
}
