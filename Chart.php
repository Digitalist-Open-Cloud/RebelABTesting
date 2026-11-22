<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

/**
 * Lays out a line chart: where the lines, the axis and the labels go, for a template to draw.
 */
class Chart
{
    public const WIDTH = 760;
    public const HEIGHT = 260;

    // The eight series colours, in the order they are handed out. A chart has no more lines.
    private const COLOURS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    private const LEFT = 56;
    private const RIGHT = 150;
    private const TOP = 12;
    private const BOTTOM = 28;
    private const GRID_LINES = 4;

    /**
     * @param array<int, array{name: string, values: array<int, ?float>}> $series a value for each
     *        point of the x axis, null where there is none
     * @return array<string, mixed>
     */
    public static function lines(array $series): array
    {
        $series = array_slice($series, 0, count(self::COLOURS));
        $points = max(array_map(function (array $line): int {
            return count($line['values']);
        }, $series) ?: [0]);
        $values = array_filter(array_merge([], ...array_column($series, 'values')), 'is_numeric');
        $top = self::roundUp($values ? max($values) : 0);

        $right = self::WIDTH - self::RIGHT;
        $bottom = self::HEIGHT - self::BOTTOM;
        $x = function (int $index) use ($points, $right): float {
            return $points > 1 ? self::LEFT + ($right - self::LEFT) * $index / ($points - 1) : ($right + self::LEFT) / 2;
        };
        $y = function (float $value) use ($top, $bottom): float {
            return $bottom - ($bottom - self::TOP) * $value / $top;
        };

        $lines = [];
        foreach ($series as $index => $line) {
            $path = [];
            $last = null;
            foreach ($line['values'] as $point => $value) {
                if ($value !== null) {
                    $last = ['x' => round($x($point), 1), 'y' => round($y($value), 1), 'value' => $value];
                    $path[] = $last['x'] . ',' . $last['y'];
                }
            }
            $lines[] = [
                'name' => $line['name'],
                'colour' => self::COLOURS[$index],
                'points' => implode(' ', $path),
                'last' => $last,
                'values' => $line['values'],
            ];
        }

        $grid = [];
        for ($step = 0; $step <= self::GRID_LINES; $step++) {
            $value = $top * $step / self::GRID_LINES;
            $grid[] = ['y' => round($y($value), 1), 'value' => $value];
        }

        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'left' => self::LEFT,
            'right' => $right,
            'bottom' => $bottom,
            'grid' => $grid,
            'lines' => self::spreadLabels($lines),
            'xs' => array_map(function (int $index) use ($x): float {
                return round($x($index), 1);
            }, $points > 0 ? range(0, $points - 1) : []),
        ];
    }

    /**
     * The top of the axis: the next round number above the highest value.
     */
    private static function roundUp(float $value): float
    {
        if ($value <= 0) {
            return 1.0;
        }
        $magnitude = 10 ** floor(log10($value));
        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($value <= $step * $magnitude) {
                return $step * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    /**
     * Lines that end close to each other get their labels moved apart, so that they can be read.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private static function spreadLabels(array $lines): array
    {
        $order = array_keys(array_filter($lines, function (array $line): bool {
            return $line['last'] !== null;
        }));
        usort($order, function (int $a, int $b) use ($lines): int {
            return $lines[$a]['last']['y'] <=> $lines[$b]['last']['y'];
        });

        $previous = null;
        foreach ($order as $index) {
            $labelY = $lines[$index]['last']['y'];
            if ($previous !== null && $labelY - $previous < 14) {
                $labelY = $previous + 14;
            }
            $lines[$index]['label_y'] = $labelY;
            $previous = $labelY;
        }

        return $lines;
    }
}
