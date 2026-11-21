<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Dao;

use Exception;
use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Piwik;
use Piwik\Plugins\RebelABTesting\Takeover;
use Piwik\Plugins\RebelABTesting\Validation\ExperimentValidator;

class Experiments
{
    public const TABLE = 'rebel_ab_testing_experiments';

    private const DUPLICATE_ENTRY = '1062';

    public function install()
    {
        if (Takeover::renameTable('simple_ab_testing_experiments', self::TABLE)) {
            // SimpleABTesting had names unique across all websites.
            Db::exec('ALTER TABLE `' . $this->table() . '` DROP INDEX `unique_name`, ADD UNIQUE KEY `unique_name` (`idsite`, `name`)');
            return;
        }

        DbHelper::createTable(self::TABLE, "
            `id` INT NOT NULL AUTO_INCREMENT,
            `idsite` INT NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `hypothesis` TEXT,
            `description` TEXT,
            `from_date` DATE NOT NULL,
            `to_date` DATE NOT NULL,
            `css_insert` TEXT,
            `js_insert` TEXT,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_name` (`idsite`, `name`)
        ");
    }

    public function uninstall()
    {
        Db::dropTables(Common::prefixTable(self::TABLE));
    }

    public function insertExperiment(int $idSite, string $name, string $hypothesis, string $description, string $fromDate, string $toDate, string $cssInsert, string $customJs): int
    {
        $this->assertValid($idSite, $name, $fromDate, $toDate);

        $this->write(
            'INSERT INTO `' . $this->table() . '` (idsite, name, hypothesis, description, `from_date`, `to_date`, css_insert, js_insert) VALUES (?,?,?,?,?,?,?,?)',
            array_merge([$idSite], self::encode([$name, $hypothesis, $description]), [$fromDate, $toDate], self::encode([$cssInsert, $customJs]))
        );

        return (int) Db::get()->lastInsertId();
    }

    public function updateExperiment(int $id, int $idSite, string $name, string $hypothesis, string $description, string $fromDate, string $toDate, string $cssInsert, string $customJs): void
    {
        $this->assertValid($idSite, $name, $fromDate, $toDate, $id);

        $this->write(
            'UPDATE `' . $this->table() . '` SET name = ?, hypothesis = ?, description = ?, `from_date` = ?, `to_date` = ?, css_insert = ?, js_insert = ? WHERE id = ? AND idsite = ?',
            array_merge(self::encode([$name, $hypothesis, $description]), [$fromDate, $toDate], self::encode([$cssInsert, $customJs]), [$id, $idSite])
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id, int $idSite): ?array
    {
        $row = Db::fetchRow('SELECT * FROM `' . $this->table() . '` WHERE id = ? AND idsite = ?', [$id, $idSite]);

        return $row ? self::decode($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllForSite(int $idSite): array
    {
        $rows = Db::fetchAll('SELECT * FROM `' . $this->table() . '` WHERE idsite = ? ORDER BY id', [$idSite]);

        return array_map([self::class, 'decode'], $rows);
    }

    public function getIdByName(int $idSite, string $name): ?int
    {
        $id = Db::fetchOne('SELECT id FROM `' . $this->table() . '` WHERE idsite = ? AND name = ?', array_merge([$idSite], self::encode([$name])));

        return $id ? (int) $id : null;
    }

    public function deleteExperiment(int $id, int $idSite): void
    {
        Db::query('DELETE FROM `' . $this->table() . '` WHERE id = ? AND idsite = ?', [$id, $idSite]);
    }

    /**
     * @return array<int, array{from_date: string, to_date: string}>
     */
    public function getDateRangesForSite(int $idSite, ?int $excludeId = null): array
    {
        $query = 'SELECT `from_date`, `to_date` FROM `' . $this->table() . '` WHERE idsite = ?';
        $params = [$idSite];
        if ($excludeId !== null) {
            $query .= ' AND id != ?';
            $params[] = $excludeId;
        }

        return Db::fetchAll($query, $params);
    }

    /**
     * Texts are stored with HTML special characters encoded, as Matomo's API handed them over when
     * the first experiments were stored.
     *
     * @param string[] $texts
     * @return string[]
     */
    private static function encode(array $texts): array
    {
        return array_map(function (string $text): string {
            return htmlspecialchars($text, Common::HTML_ENCODING_QUOTE_STYLE, 'UTF-8');
        }, $texts);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        foreach (['name', 'hypothesis', 'description', 'css_insert', 'js_insert'] as $field) {
            $row[$field] = Common::unsanitizeInputValue((string) ($row[$field] ?? ''));
        }

        return $row;
    }

    private function table(): string
    {
        return Common::prefixTable(self::TABLE);
    }

    /**
     * @param array<int, mixed> $params
     */
    private function write(string $query, array $params): void
    {
        try {
            Db::query($query, $params);
        } catch (Exception $e) {
            if (Db::get()->isErrNo($e, self::DUPLICATE_ENTRY)) {
                throw new Exception(Piwik::translate('RebelABTesting_ErrorNameExists'));
            }
            throw $e;
        }
    }

    /**
     * @param int|null $excludeId the experiment that is updated, which may overlap itself
     */
    private function assertValid(int $idSite, string $name, string $fromDate, string $toDate, ?int $excludeId = null): void
    {
        $error = ExperimentValidator::validateName($name) ?? ExperimentValidator::validateDates($fromDate, $toDate);

        if ($error === null && ExperimentValidator::hasOverlap($this->getDateRangesForSite($idSite, $excludeId), $fromDate, $toDate)) {
            $error = 'RebelABTesting_ErrorOverlap';
        }

        if ($error !== null) {
            throw new Exception(Piwik::translate($error));
        }
    }
}
