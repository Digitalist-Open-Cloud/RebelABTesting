<?php

namespace Piwik\Plugins\RebelABTesting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\RebelABTesting\Audience;

/**
 * @group RebelABTesting
 * @group Plugins
 */
final class AudienceTest extends TestCase
{
    private const EVERYONE = ['visitor' => '', 'devices' => [], 'campaign' => '', 'referrer' => '', 'languages' => []];

    public function testWhatIsNotAnAudienceIsEveryone(): void
    {
        foreach ([null, 'everyone', [], ['devices' => 'mobile', 'campaign' => ['a'], 'languages' => [['sv']]]] as $given) {
            $this->assertSame(self::EVERYONE, Audience::normalize($given));
            $this->assertNull(Audience::validate(Audience::normalize($given)));
        }
    }

    /**
     * @dataProvider getAudiences
     */
    public function testAudience(array $audience, bool $isValid): void
    {
        $this->assertSame($isValid ? null : 'RebelABTesting_ErrorAudience', Audience::validate(Audience::normalize($audience)));
    }

    public function getAudiences(): array
    {
        return [
            'new visitors' => [['visitor' => 'new'], true],
            'visitors of another kind' => [['visitor' => 'loyal'], false],
            'languages with and without a region' => [['languages' => 'sv, en-GB, zh-hant'], true],
            'a language that is not a code' => [['languages' => 'svenska!'], false],
            'too many languages' => [['languages' => implode(',', array_map(function (int $i): string {
                return chr(97 + intdiv($i, 26)) . chr(97 + $i % 26);
            }, range(0, 20)))], false],
            'a campaign that is too long' => [['campaign' => str_repeat('a', 201)], false],
        ];
    }

    public function testOnlyKnownDevicesAreKeptInTheirOrder(): void
    {
        $this->assertSame(['desktop', 'mobile'], Audience::normalize(['devices' => ['mobile', 'fridge', 'desktop']])['devices']);
    }
}
