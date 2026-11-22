<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Piwik\Piwik;

/**
 * Changes to start a variant from, for the things that are tested most often.
 */
class Templates
{
    public const INSERTED_CLASS = 'rebel-experiment-insert';

    /**
     * @return array<string, array{name: string, changes: array<int, array{selector: string, action: string, value: string}>}>
     */
    public static function getAll(): array
    {
        $inserted = '.' . self::INSERTED_CLASS;
        $text = function (string $key): string {
            return Piwik::translate('RebelABTesting_TemplateText_' . $key);
        };

        $templates = [
            'headline' => [['h1', 'text', $text('headline')]],
            'button_text' => [['', 'text', $text('button_text')]],
            'button_look' => [['', 'style', 'background-color: #2e7d32; color: #ffffff']],
            'hide' => [['', 'hide', '']],
            'banner' => [['body', 'insert_first', $text('banner')], [$inserted, 'style', 'background: #fff3cd; color: #222222; padding: 10px; text-align: center']],
            'image' => [['', 'image', '']],
            'trust_line' => [['', 'insert_after', $text('trust_line')], [$inserted, 'style', 'font-size: 0.9em; margin-top: 6px']],
            'sticky_button' => [['', 'style', 'position: fixed; bottom: 16px; right: 16px; z-index: 1000']],
            'shorter_form' => [['', 'hide', ''], ['', 'hide', '']],
        ];

        $all = [];
        foreach ($templates as $key => $changes) {
            $all[$key] = [
                'name' => Piwik::translate('RebelABTesting_Template_' . $key),
                'changes' => array_map(function (array $change): array {
                    return array_combine(['selector', 'action', 'value'], $change);
                }, $changes),
            ];
        }

        return $all;
    }
}
