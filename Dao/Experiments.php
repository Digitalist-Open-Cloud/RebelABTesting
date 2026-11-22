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
            `exclude_match` VARCHAR(20) NOT NULL DEFAULT 'none',
            `exclude_value` TEXT,
            `confidence` TINYINT UNSIGNED NOT NULL DEFAULT 95,
            `variants` MEDIUMTEXT NOT NULL,
            `primary_measure` TEXT,
            `guardrails` TEXT,
            `kind` VARCHAR(20) NOT NULL DEFAULT 'ab',
            `door` TEXT,
            `preview_token` CHAR(32) NOT NULL,
            `created_by` VARCHAR(100) NOT NULL DEFAULT '',
            `notify` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `notified` VARCHAR(40) NOT NULL DEFAULT '',
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
     *                                         traffic_share, target_match, target_value,
     *                                         exclude_match, exclude_value, confidence, variants
     *                                         primary_measure, guardrails, kind and door
     */
    public function insertExperiment(int $idSite, array $experiment): int
    {
        $values = $this->getValidValues($experiment);
        $values['preview_token'] = bin2hex(random_bytes(16));
        $values['created_by'] = Piwik::getCurrentUserLogin();

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

    /**
     * The experiments whose creator wants to hear about their result and has not heard the last of it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllToNotify(): array
    {
        $rows = Db::fetchAll('SELECT * FROM `' . $this->table() . "` WHERE notify = 1 AND created_by != '' AND notified != ? AND from_date <= ? ORDER BY id", [
            self::STATUS_ENDED,
            Date::now()->toString(),
        ]);

        return array_map([self::class, 'enrich'], $rows);
    }

    /**
     * @param string $state what the creator of the experiment was told last
     */
    public function setNotified(int $id, string $state): void
    {
        Db::query('UPDATE `' . $this->table() . '` SET notified = ? WHERE id = ?', [$state, $id]);
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
     * The highest variant a visitor can have: 1 is the original, and a fake door only has the
     * visitors who get the door, as variant 2.
     *
     * @param array<string, mixed> $experiment
     */
    public static function getMaxVariant(array $experiment): int
    {
        return ExperimentValidator::countsWhatIsShown($experiment['kind']) ? 2 : count($experiment['variants']) + 1;
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
        $row['primary_measure'] = $row['primary_measure'] === null ? null : json_decode($row['primary_measure'], true);
        $row['guardrails'] = json_decode((string) $row['guardrails'], true) ?: [];
        $row['target_value'] = (string) $row['target_value'];
        $row['exclude_value'] = (string) $row['exclude_value'];
        $row['confidence'] = (int) $row['confidence'];
        $row['notify'] = (bool) $row['notify'];
        $row['variants'] = ExperimentValidator::normalizeVariants(json_decode($row['variants'], true));
        $row['door'] = $row['door'] === null ? null : json_decode($row['door'], true);
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
            'exclude_match' => (string) ($experiment['exclude_match'] ?? ExperimentValidator::EXCLUDE_NONE),
            'exclude_value' => trim((string) ($experiment['exclude_value'] ?? '')),
            'confidence' => (int) ($experiment['confidence'] ?? 95),
            'notify' => empty($experiment['notify']) ? 0 : 1,
            'primary_measure' => empty($experiment['primary_measure']) ? null : json_encode($experiment['primary_measure']),
            'guardrails' => json_encode($experiment['guardrails'] ?? []),
        ];
        $kind = (string) ($experiment['kind'] ?? ExperimentValidator::KIND_AB);
        $hasVariants = !ExperimentValidator::countsWhatIsShown($kind);
        $variants = $hasVariants ? ExperimentValidator::normalizeVariants($experiment['variants'] ?? null) : [];
        $door = null;
        $kindError = null;
        if ($kind === ExperimentValidator::KIND_FAKE_DOOR) {
            $door = ExperimentValidator::normalizeDoor($experiment['door'] ?? null);
            $kindError = ExperimentValidator::validateDoor($door);
        } elseif ($kind === ExperimentValidator::KIND_WATCH) {
            $door = ExperimentValidator::normalizeWatch($experiment['door'] ?? null);
            $kindError = ExperimentValidator::validateWatch($door);
        } elseif ($kind === ExperimentValidator::KIND_AB) {
            $kindError = ExperimentValidator::validateVariants($variants);
        }

        $error = ExperimentValidator::validateName($values['name'])
            ?? ExperimentValidator::validateDates($values['from_date'], $values['to_date'])
            ?? ExperimentValidator::validateTrafficShare($values['traffic_share'])
            ?? ExperimentValidator::validateTarget($values['target_match'], $values['target_value'])
            ?? ExperimentValidator::validateExclude($values['exclude_match'], $values['exclude_value'])
            ?? ExperimentValidator::validateConfidence($values['confidence'])
            ?? (in_array($kind, ExperimentValidator::KINDS, true) ? null : 'RebelABTesting_ErrorKind')
            ?? $kindError;

        if ($error !== null) {
            throw new Exception(Piwik::translate($error));
        }

        if ($values['target_match'] === ExperimentValidator::MATCH_ANY) {
            $values['target_value'] = '';
        }
        if ($values['exclude_match'] === ExperimentValidator::EXCLUDE_NONE) {
            $values['exclude_value'] = '';
        }
        $values['variants'] = json_encode($variants);
        $values['kind'] = $kind;
        $values['door'] = $door === null ? null : json_encode($door);
        if (!$hasVariants) {
            $values['primary_measure'] = null;
            $values['guardrails'] = '[]';
        }

        return $values;
    }
}
