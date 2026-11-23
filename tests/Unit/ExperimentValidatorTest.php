<?php

namespace Piwik\Plugins\RebelExperiments\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\RebelExperiments\Validation\ExperimentValidator;

/**
 * @group RebelExperiments
 * @group Plugins
 */
final class ExperimentValidatorTest extends TestCase
{
    /**
     * @dataProvider getNames
     */
    public function testName(string $name, ?string $error): void
    {
        $this->assertSame($error, ExperimentValidator::validateName($name));
    }

    public function getNames(): array
    {
        return [
            'ordinary' => ['Spring sale, v2', null],
            'as long as the column' => [str_repeat('a', 255), null],
            'empty' => ['', 'RebelExperiments_ErrorNameEmpty'],
            'only spaces' => ['   ', 'RebelExperiments_ErrorNameEmpty'],
            'too long' => [str_repeat('a', 256), 'RebelExperiments_ErrorNameTooLong'],
        ];
    }

    /**
     * @dataProvider getDates
     */
    public function testDates(string $from, string $to, ?string $error): void
    {
        $this->assertSame($error, ExperimentValidator::validateDates($from, $to));
    }

    public function getDates(): array
    {
        return [
            'a month' => ['2026-01-01', '2026-01-31', null],
            'one day' => ['2026-01-01', '2026-01-01', null],
            'last day first' => ['2026-01-02', '2026-01-01', 'RebelExperiments_ErrorDateOrder'],
            'not a date' => ['soon', '2026-01-31', 'RebelExperiments_ErrorDateFormat'],
            'a day that does not exist' => ['2026-02-30', '2026-03-31', 'RebelExperiments_ErrorDateFormat'],
            'with a time' => ['2026-01-01 10:00', '2026-01-31', 'RebelExperiments_ErrorDateFormat'],
            'empty' => ['', '', 'RebelExperiments_ErrorDateFormat'],
        ];
    }

    /**
     * @dataProvider getTargets
     */
    public function testTarget(string $match, string $value, ?string $error): void
    {
        $this->assertSame($error, ExperimentValidator::validateTarget($match, $value));
    }

    public function getTargets(): array
    {
        return [
            'all pages' => ['any', '', null],
            'pages of a part' => ['contains', '/pricing', null],
            'unknown comparison' => ['sounds_like', '/pricing', 'RebelExperiments_ErrorTargetMatch'],
            'nothing to compare with' => ['equals', '', 'RebelExperiments_ErrorTargetValue'],
            'too long' => ['contains', str_repeat('a', 1001), 'RebelExperiments_ErrorTargetValue'],
        ];
    }

    public function testPagesCanBeLeftOutAndTheLevelOfCertaintyIsOneOfThree(): void
    {
        $this->assertNull(ExperimentValidator::validateExclude('none', ''));
        $this->assertNull(ExperimentValidator::validateExclude('regexp', '/checkout|/login'));
        $this->assertSame('RebelExperiments_ErrorTargetMatch', ExperimentValidator::validateExclude('any', ''));
        $this->assertSame('RebelExperiments_ErrorTargetValue', ExperimentValidator::validateExclude('contains', ''));

        foreach ([90, 95, 99] as $confidence) {
            $this->assertNull(ExperimentValidator::validateConfidence($confidence));
        }
        $this->assertSame('RebelExperiments_ErrorConfidence', ExperimentValidator::validateConfidence(100));
    }

    public function testTrafficShareIsAPercentage(): void
    {
        $this->assertNull(ExperimentValidator::validateTrafficShare(1));
        $this->assertNull(ExperimentValidator::validateTrafficShare(100));
        $this->assertSame('RebelExperiments_ErrorTrafficShare', ExperimentValidator::validateTrafficShare(0));
        $this->assertSame('RebelExperiments_ErrorTrafficShare', ExperimentValidator::validateTrafficShare(101));
    }

    public function testVariantsKeepOnlyTheirNameChangesCssAndJavaScript(): void
    {
        $this->assertSame(
            [
                ['name' => 'Green', 'changes' => [['selector' => 'h1', 'action' => 'text', 'value' => 'New'], ['selector' => '.ad', 'action' => 'hide', 'value' => '']], 'redirect' => '', 'css' => 'a{}', 'js' => ''],
                ['name' => '', 'changes' => [], 'redirect' => '', 'css' => '', 'js' => 'x()'],
            ],
            ExperimentValidator::normalizeVariants([
                [
                    'name' => ' Green ',
                    'css' => 'a{}',
                    'weight' => 9,
                    'changes' => [
                        ['selector' => ' h1 ', 'action' => 'text', 'value' => ' New ', 'onclick' => 'x()'],
                        ['selector' => '', 'action' => 'text', 'value' => 'a row left empty'],
                        ['selector' => '.ad', 'action' => 'hide', 'value' => 'ignored'],
                        'text',
                    ],
                ],
                'text',
                ['js' => 'x()', 'changes' => 'none'],
            ])
        );
        $this->assertSame([], ExperimentValidator::normalizeVariants('not a list'));
    }

    /**
     * @dataProvider getDoors
     */
    public function testFakeDoor(array $change, ?string $error): void
    {
        $door = ExperimentValidator::normalizeDoor($change + ['selector' => ' #menu ', 'label' => 'Export', 'message' => 'Soon']);

        $this->assertSame($error, ExperimentValidator::validateDoor($door));
    }

    public function getDoors(): array
    {
        return [
            'the least' => [[], null],
            'everything' => [['position' => 'prepend', 'element' => 'link', 'css_class' => 'btn btn-primary_2'], null],
            'no element to put it next to' => [['selector' => ''], 'RebelExperiments_ErrorDoor'],
            'no text' => [['label' => ' '], 'RebelExperiments_ErrorDoor'],
            'no message' => [['message' => ''], 'RebelExperiments_ErrorDoor'],
            'unknown position' => [['position' => 'above'], 'RebelExperiments_ErrorDoor'],
            'unknown element' => [['element' => 'script'], 'RebelExperiments_ErrorDoor'],
            'a class that is not a class' => [['css_class' => 'a" onclick="x()'], 'RebelExperiments_ErrorDoor'],
        ];
    }

    public function testAFakeDoorIsAButtonAfterItsElementUnlessToldOtherwise(): void
    {
        $this->assertSame(
            ['selector' => '#menu', 'position' => 'after', 'element' => 'button', 'label' => 'Export', 'css_class' => '', 'message' => 'Soon'],
            ExperimentValidator::normalizeDoor(['selector' => ' #menu ', 'label' => 'Export', 'message' => 'Soon', 'onclick' => 'x()', 'position' => ['a']])
        );
    }

    /**
     * @dataProvider getChanges
     */
    public function testChange(string $action, string $value, bool $isValid, string $selector = 'h1'): void
    {
        $variants = ExperimentValidator::normalizeVariants([['changes' => [['selector' => $selector, 'action' => $action, 'value' => $value]]]]);

        $this->assertSame($isValid ? null : 'RebelExperiments_ErrorChange', ExperimentValidator::validateVariants($variants));
    }

    public function getChanges(): array
    {
        return [
            'a text' => ['text', 'New <b>headline</b>', true],
            'no text' => ['text', '', false],
            'hide' => ['hide', '', true],
            'classes' => ['add_class', 'big green_2', true],
            'a class that is not a class' => ['add_class', 'big" onclick="x()', false],
            'a style' => ['style', 'background: green; color: white', true],
            'an image on the website' => ['image', '/images/new.png', true],
            'an image elsewhere' => ['image', 'https://cdn.example.org/new.png', true],
            'a link to a page' => ['link', 'pricing?plan=2', true],
            'a link to a mail address' => ['link', 'mailto:info@example.org', true],
            'a link that runs code' => ['link', 'javascript:alert(1)', false],
            'a link that runs code, in capitals' => ['link', 'JavaScript:alert(1)', false],
            'an image from data' => ['image', 'data:text/html,<script>x()</script>', false],
            'a text after an element' => ['insert_after', 'Free <returns>', true],
            'a text before an element' => ['insert_before', 'New', true],
            'a text at the top of an element' => ['insert_first', 'Free shipping', true],
            'no text to insert' => ['insert_after', '', false],
            'an unknown action' => ['html', '<script>x()</script>', false],
            'a selector that is too long' => ['hide', '', false, 'a' . str_repeat('b', 500)],
        ];
    }

    /**
     * @dataProvider getRedirects
     */
    public function testAVariantCanSendToAnotherPage(string $address, bool $isValid): void
    {
        $variants = ExperimentValidator::normalizeVariants([['redirect' => $address]]);

        $this->assertSame(trim($address), $variants[0]['redirect']);
        $this->assertSame($isValid ? null : 'RebelExperiments_ErrorRedirect', ExperimentValidator::validateVariants($variants));
    }

    public function getRedirects(): array
    {
        return [
            'no other page' => ['', true],
            'a page of the website' => [' /pricing-b?plan=2 ', true],
            'a page elsewhere' => ['https://shop.example.org/pricing-b', true],
            'an address that runs code' => ['javascript:alert(1)', false],
            'too long' => ['/' . str_repeat('a', 2000), false],
        ];
    }

    public function testAVariantHasTwentyChangesAtMost(): void
    {
        $change = ['selector' => 'h1', 'action' => 'hide'];

        $this->assertNull(ExperimentValidator::validateVariants(ExperimentValidator::normalizeVariants([['changes' => array_fill(0, 20, $change)]])));
        $this->assertSame('RebelExperiments_ErrorChange', ExperimentValidator::validateVariants(ExperimentValidator::normalizeVariants([['changes' => array_fill(0, 21, $change)]])));
    }

    public function testOnlyCssAndJavaScriptAreCode(): void
    {
        $changes = [['selector' => 'h1', 'action' => 'style', 'value' => 'color: red']];

        $this->assertFalse(ExperimentValidator::hasCode(ExperimentValidator::normalizeVariants([['changes' => $changes], ['css' => ' ', 'js' => "\n"]])));
        $this->assertTrue(ExperimentValidator::hasCode(ExperimentValidator::normalizeVariants([['changes' => $changes], ['css' => 'a{}']])));
        $this->assertTrue(ExperimentValidator::hasCode(ExperimentValidator::normalizeVariants([['js' => 'x()']])));
    }

    public function testAWatchedElementNeedsASelector(): void
    {
        $this->assertSame(['selector' => '.signup'], ExperimentValidator::normalizeWatch(['selector' => ' .signup ', 'label' => 'ignored']));
        $this->assertNull(ExperimentValidator::validateWatch(['selector' => '.signup']));
        $this->assertSame('RebelExperiments_ErrorWatch', ExperimentValidator::validateWatch(ExperimentValidator::normalizeWatch([])));
        $this->assertSame('RebelExperiments_ErrorWatch', ExperimentValidator::validateWatch(ExperimentValidator::normalizeWatch(['selector' => ['a']])));
        $this->assertSame('RebelExperiments_ErrorWatch', ExperimentValidator::validateWatch(['selector' => str_repeat('a', 501)]));
    }

    public function testAnExperimentHasOneToTenVariants(): void
    {
        $variant = ['name' => '', 'changes' => [], 'redirect' => '', 'css' => '', 'js' => ''];

        $this->assertNull(ExperimentValidator::validateVariants([$variant]));
        $this->assertNull(ExperimentValidator::validateVariants(array_fill(0, 10, $variant)));
        $this->assertSame('RebelExperiments_ErrorVariants', ExperimentValidator::validateVariants([]));
        $this->assertSame('RebelExperiments_ErrorVariants', ExperimentValidator::validateVariants(array_fill(0, 11, $variant)));
    }
}
