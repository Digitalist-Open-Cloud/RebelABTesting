<?php

namespace Piwik\Plugins\RebelABTesting\tests\Integration;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;
use Piwik\Plugins\RebelABTesting\RebelABTesting;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group RebelABTesting
 * @group Plugins
 */
class TakeoverTest extends IntegrationTestCase
{
    private const TABLES = [
        'simple_ab_testing_experiments' => Experiments::TABLE,
        'simple_ab_testing_log' => LogExperiment::TABLE,
    ];

    public function tearDown(): void
    {
        foreach (self::TABLES as $old => $new) {
            Db::exec('DROP TABLE IF EXISTS `' . Common::prefixTable($old) . '`');
        }
        (new RebelABTesting())->install();

        parent::tearDown();
    }

    public function testInstallingTakesOverTheTablesOfSimpleABTesting()
    {
        Db::query(
            'INSERT INTO ' . Common::prefixTable(Experiments::TABLE) . ' (idsite, name, `from_date`, `to_date`) VALUES (?,?,?,?)',
            [1, 'kept', '2024-03-01', '2024-03-31']
        );
        // As SimpleABTesting made it.
        Db::exec('ALTER TABLE `' . Common::prefixTable(Experiments::TABLE) . '` DROP INDEX `unique_name`, ADD UNIQUE KEY `unique_name` (`name`)');
        foreach (self::TABLES as $old => $new) {
            Db::exec('RENAME TABLE `' . Common::prefixTable($new) . '` TO `' . Common::prefixTable($old) . '`');
        }

        (new RebelABTesting())->install();

        $this->assertSame('kept', (new Experiments())->getById(1, 1)['name']);
        $this->assertGreaterThan(0, (new Experiments())->insertExperiment(2, 'kept', '', '', '2024-03-01', '2024-03-31', '', ''));
        $this->assertSame('0', (string) Db::fetchOne('SELECT COUNT(*) FROM ' . Common::prefixTable(LogExperiment::TABLE)));
        $this->assertFalse(Db::fetchOne('SHOW TABLES LIKE ?', [Common::prefixTable('simple_ab_testing_log')]));
    }

    public function testInstallingKeepsTheTablesItAlreadyHas()
    {
        Db::query(
            'INSERT INTO ' . Common::prefixTable(Experiments::TABLE) . ' (idsite, name, `from_date`, `to_date`) VALUES (?,?,?,?)',
            [1, 'current', '2024-03-01', '2024-03-31']
        );
        Db::exec('CREATE TABLE `' . Common::prefixTable('simple_ab_testing_experiments') . '` (id INT)');

        (new RebelABTesting())->install();

        $this->assertSame('current', (new Experiments())->getById(1, 1)['name']);
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);

        $fixture->extraPluginsToLoad = ['RebelABTesting'];
    }
}
