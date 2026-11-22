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
 * The visitors an experiment is for. The tag decides in the browser whether a visitor is one of
 * them, when the visitor first meets the experiment.
 */
class Audience
{
    public const VISITORS = ['', 'new', 'returning'];
    public const DEVICES = ['desktop', 'tablet', 'mobile'];

    private const TEXT_MAX_LENGTH = 200;

    /**
     * @param mixed $audience what a request handed over
     * @return array{visitor: string, devices: string[], campaign: string, referrer: string, languages: string[]}
     */
    public static function normalize($audience): array
    {
        $audience = is_array($audience) ? $audience : [];
        $text = function (string $field) use ($audience): string {
            return trim(is_scalar($audience[$field] ?? '') ? (string) ($audience[$field] ?? '') : '');
        };
        $languages = $audience['languages'] ?? [];
        $languages = is_array($languages) ? $languages : explode(',', (string) $languages);

        return [
            'visitor' => $text('visitor'),
            'devices' => array_values(array_intersect(self::DEVICES, is_array($audience['devices'] ?? null) ? $audience['devices'] : [])),
            'campaign' => $text('campaign'),
            'referrer' => $text('referrer'),
            'languages' => array_values(array_unique(array_filter(array_map(function ($language): string {
                return strtolower(trim(is_scalar($language) ? (string) $language : ''));
            }, $languages)))),
        ];
    }

    /**
     * @param array{visitor: string, devices: string[], campaign: string, referrer: string, languages: string[]} $audience
     * @return string|null the key of the translation of an error
     */
    public static function validate(array $audience): ?string
    {
        if (
            !in_array($audience['visitor'], self::VISITORS, true)
            || mb_strlen($audience['campaign']) > self::TEXT_MAX_LENGTH
            || mb_strlen($audience['referrer']) > self::TEXT_MAX_LENGTH
            || count($audience['languages']) > 20
        ) {
            return 'RebelABTesting_ErrorAudience';
        }
        foreach ($audience['languages'] as $language) {
            if (!preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $language)) {
                return 'RebelABTesting_ErrorAudience';
            }
        }

        return null;
    }

    /**
     * @param array{visitor: string, devices: string[], campaign: string, referrer: string, languages: string[]} $audience
     * @return string who the experiment is for, in words, or an empty text when it is for everyone
     */
    public static function describe(array $audience): string
    {
        $parts = [];
        if ($audience['visitor'] !== '') {
            $parts[] = Piwik::translate('RebelABTesting_AudienceVisitor_' . $audience['visitor']);
        }
        if (!empty($audience['devices']) && count($audience['devices']) < count(self::DEVICES)) {
            $parts[] = implode(', ', array_map(function (string $device): string {
                return Piwik::translate('RebelABTesting_AudienceDevice_' . $device);
            }, $audience['devices']));
        }
        if ($audience['campaign'] !== '') {
            $parts[] = Piwik::translate('RebelABTesting_AudienceCampaignIs', $audience['campaign']);
        }
        if ($audience['referrer'] !== '') {
            $parts[] = Piwik::translate('RebelABTesting_AudienceReferrerIs', $audience['referrer']);
        }
        if (!empty($audience['languages'])) {
            $parts[] = Piwik::translate('RebelABTesting_AudienceLanguageIs', implode(', ', $audience['languages']));
        }

        return implode('; ', $parts);
    }
}
