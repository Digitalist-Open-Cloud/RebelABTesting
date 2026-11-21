<?php

namespace Piwik\Plugins\RebelABTesting;

/**
 * Groups rows of a query by their label and then by one or two identity columns, such as the
 * variant and the goal. Only the metric columns are kept as values.
 */
final class RowGrouper
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $metricColumns
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function groupByOneLevel(array $rows, string $identityColumn, array $metricColumns): array
    {
        $tree = [];
        foreach ($rows as $row) {
            $label = (string) $row['label'];
            $identity = (string) $row[$identityColumn];
            $tree[$label][$identity] = self::extractMetrics($row, $metricColumns);
        }
        return $tree;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $metricColumns
     * @return array<string, array<string, array<string, array<string, mixed>>>>
     */
    public static function groupByTwoLevels(
        array $rows,
        string $outerIdentityColumn,
        string $innerIdentityColumn,
        array $metricColumns
    ): array {
        $tree = [];
        foreach ($rows as $row) {
            $label = (string) $row['label'];
            $outer = (string) $row[$outerIdentityColumn];
            $inner = (string) $row[$innerIdentityColumn];
            $tree[$label][$outer][$inner] = self::extractMetrics($row, $metricColumns);
        }
        return $tree;
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $metricColumns
     * @return array<string, mixed>
     */
    private static function extractMetrics(array $row, array $metricColumns): array
    {
        $metrics = [];
        foreach ($metricColumns as $column) {
            $metrics[$column] = $row[$column];
        }
        return $metrics;
    }
}
