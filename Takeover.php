<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Piwik\Common;
use Piwik\Db;

/**
 * Takes over the data of the SimpleABTesting plugin, which this plugin replaces.
 */
class Takeover
{
    /**
     * @return bool whether the table of SimpleABTesting was taken over
     */
    public static function renameTable(string $oldTable, string $newTable): bool
    {
        $old = Common::prefixTable($oldTable);
        $new = Common::prefixTable($newTable);

        if (!self::tableExists($old) || self::tableExists($new)) {
            return false;
        }

        Db::exec("RENAME TABLE `$old` TO `$new`");

        return true;
    }

    public static function renameTagType(): void
    {
        $table = Common::prefixTable('tagmanager_tag');
        if (self::tableExists($table)) {
            Db::query("UPDATE `$table` SET `type` = 'RebelABTesting' WHERE `type` = 'SimpleABTesting'");
        }
    }

    private static function tableExists(string $table): bool
    {
        return (bool) Db::fetchOne('SHOW TABLES LIKE ?', [$table]);
    }
}
