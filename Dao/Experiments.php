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
use Piwik\Date;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Piwik;
use Piwik\Plugins\RebelABTesting\Validation\ExperimentValidator;

class Experiments
{
    public const TABLE = 'rebel_ab_testing_experiments';

    public const STATUS_PLANNED = 'planned';
    public const STATUS_RUNNING = 'running';
    public const STATUS_ENDED = 'ended';

    private const DUPLICATE_ENTRY = '1062';

    public function install()
    {
        DbHelper::createTable(self::TABLE, "
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `idsite` INT UNSIGNED NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `hypothesis` TEXT,
            `description` TEXT,
            `from_date` DATE NOT NULL,
            `to_date` DATE NOT NULL,
            `stopped_at` DATETIME NULL,
            `traffic_share` TINYINT UNSIGNED NOT NULL DEFAULT 100,
            `target_match` VARCHAR(20) NOT NULL DEFAULT 'any',
            `target_value` TEXT,
            `variants` MEDIUMTEXT NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_name` (`idsite`, `name`)
        ");
    }

    public function uninstall()
    {
        Db::dropTables(Common::prefixTable(self::TABLE));
    }

    /**
     * @param array<string, mixed> $experiment name, hypothesis, description, from_date, to_date,
     *                                         traffic_share, target_match, target_value and variants
     */
    public function insertExperiment(int $idSite, array $experiment): int
    {
        $values = $this->getValidValues($experiment);

        $this->write(
            'INSERT INTO `' . $this->table() . '` (idsite, `' . implode('`, `', array_keys($values)) . '`) VALUES (?' . str_repeat(',?', count($values)) . ')',
            array_merge([$idSite], array_values($values))
        );

        return (int) Db::get()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $experiment
     */
    public function updateExperiment(int $id, int $idSite, array $experiment): void
    {
        $values = $this->getValidValues($experiment);

        $this->write(
            'UPDATE `' . $this->table() . '` SET `' . implode('` = ?, `', array_keys($values)) . '` = ? WHERE id = ? AND idsite = ?',
            array_merge(array_values($values), [$id, $idSite])
        );
    }

    /**
     * Ends an experiment now. Visitors get the original and are no longer counted.
     */
    public function stopExperiment(int $id, int $idSite): void
    {
        Db::query(
            'UPDATE `' . $this->table() . '` SET stopped_at = ? WHERE id = ? AND idsite = ? AND stopped_at IS NULL',
            [Date::now()->getDatetime(), $id, $idSite]
        );
    }

    public function deleteExperiment(int $id, int $idSite): void
    {
        Db::query('DELETE FROM `' . $this->table() . '` WHERE id = ? AND idsite = ?', [$id, $idSite]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id, int $idSite): ?array
    {
        $row = Db::fetchRow('SELECT * FROM `' . $this->table() . '` WHERE id = ? AND idsite = ?', [$id, $idSite]);

        return $row ? self::enrich($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllForSite(int $idSite): array
    {
        $rows = Db::fetchAll('SELECT * FROM `' . $this->table() . '` WHERE idsite = ? ORDER BY id', [$idSite]);

        return array_map([self::class, 'enrich'], $rows);
    }

    /**
     * @param array<string, mixed> $experiment
     */
    public static function getStatus(array $experiment, int $timestamp): string
    {
        $day = gmdate('Y-m-d', $timestamp);

        if ($day > $experiment['to_date'] || (!empty($experiment['stopped_at']) && strtotime($experiment['stopped_at'] . ' UTC') <= $timestamp)) {
            return self::STATUS_ENDED;
        }

        return $day < $experiment['from_date'] ? self::STATUS_PLANNED : self::STATUS_RUNNING;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function enrich(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['idsite'] = (int) $row['idsite'];
        $row['traffic_share'] = (int) $row['traffic_share'];
        $row['target_value'] = (string) $row['target_value'];
        $row['variants'] = json_decode($row['variants'], true) ?: [];
        $row['status'] = self::getStatus($row, Date::now()->getTimestamp());

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
     * @param array<string, mixed> $experiment
     * @return array<string, mixed> the values to store, by column
     */
    private function getValidValues(array $experiment): array
    {
        $values = [
            'name' => (string) ($experiment['name'] ?? ''),
            'hypothesis' => (string) ($experiment['hypothesis'] ?? ''),
            'description' => (string) ($experiment['description'] ?? ''),
            'from_date' => (string) ($experiment['from_date'] ?? ''),
            'to_date' => (string) ($experiment['to_date'] ?? ''),
            'traffic_share' => (int) ($experiment['traffic_share'] ?? 100),
            'target_match' => (string) ($experiment['target_match'] ?? ExperimentValidator::MATCH_ANY),
            'target_value' => trim((string) ($experiment['target_value'] ?? '')),
        ];
        $variants = ExperimentValidator::normalizeVariants($experiment['variants'] ?? null);

        $error = ExperimentValidator::validateName($values['name'])
            ?? ExperimentValidator::validateDates($values['from_date'], $values['to_date'])
            ?? ExperimentValidator::validateTrafficShare($values['traffic_share'])
            ?? ExperimentValidator::validateTarget($values['target_match'], $values['target_value'])
            ?? ExperimentValidator::validateVariants($variants);

        if ($error !== null) {
            throw new Exception(Piwik::translate($error));
        }

        if ($values['target_match'] === ExperimentValidator::MATCH_ANY) {
            $values['target_value'] = '';
        }
        $values['variants'] = json_encode($variants);

        return $values;
    }
}
