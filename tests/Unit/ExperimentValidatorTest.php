<?php

namespace Piwik\Plugins\RebelABTesting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\RebelABTesting\Validation\ExperimentValidator;

/**
 * @group RebelABTesting
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
            'empty' => ['', 'RebelABTesting_ErrorNameEmpty'],
            'only spaces' => ['   ', 'RebelABTesting_ErrorNameEmpty'],
            'too long' => [str_repeat('a', 256), 'RebelABTesting_ErrorNameTooLong'],
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
            'last day first' => ['2026-01-02', '2026-01-01', 'RebelABTesting_ErrorDateOrder'],
            'not a date' => ['soon', '2026-01-31', 'RebelABTesting_ErrorDateFormat'],
            'a day that does not exist' => ['2026-02-30', '2026-03-31', 'RebelABTesting_ErrorDateFormat'],
            'with a time' => ['2026-01-01 10:00', '2026-01-31', 'RebelABTesting_ErrorDateFormat'],
            'empty' => ['', '', 'RebelABTesting_ErrorDateFormat'],
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
            'unknown comparison' => ['sounds_like', '/pricing', 'RebelABTesting_ErrorTargetMatch'],
            'nothing to compare with' => ['equals', '', 'RebelABTesting_ErrorTargetValue'],
            'too long' => ['contains', str_repeat('a', 1001), 'RebelABTesting_ErrorTargetValue'],
        ];
    }

    public function testTrafficShareIsAPercentage(): void
    {
        $this->assertNull(ExperimentValidator::validateTrafficShare(1));
        $this->assertNull(ExperimentValidator::validateTrafficShare(100));
        $this->assertSame('RebelABTesting_ErrorTrafficShare', ExperimentValidator::validateTrafficShare(0));
        $this->assertSame('RebelABTesting_ErrorTrafficShare', ExperimentValidator::validateTrafficShare(101));
    }

    public function testVariantsKeepOnlyTheirNameChangesCssAndJavaScript(): void
    {
        $this->assertSame(
            [
                ['name' => 'Green', 'changes' => [['selector' => 'h1', 'action' => 'text', 'value' => 'New'], ['selector' => '.ad', 'action' => 'hide', 'value' => '']], 'css' => 'a{}', 'js' => ''],
                ['name' => '', 'changes' => [], 'css' => '', 'js' => 'x()'],
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
            'no element to put it next to' => [['selector' => ''], 'RebelABTesting_ErrorDoor'],
            'no text' => [['label' => ' '], 'RebelABTesting_ErrorDoor'],
            'no message' => [['message' => ''], 'RebelABTesting_ErrorDoor'],
            'unknown position' => [['position' => 'above'], 'RebelABTesting_ErrorDoor'],
            'unknown element' => [['element' => 'script'], 'RebelABTesting_ErrorDoor'],
            'a class that is not a class' => [['css_class' => 'a" onclick="x()'], 'RebelABTesting_ErrorDoor'],
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

        $this->assertSame($isValid ? null : 'RebelABTesting_ErrorChange', ExperimentValidator::validateVariants($variants));
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
            'an unknown action' => ['html', '<script>x()</script>', false],
            'a selector that is too long' => ['hide', '', false, 'a' . str_repeat('b', 500)],
        ];
    }

    public function testAVariantHasTwentyChangesAtMost(): void
    {
        $change = ['selector' => 'h1', 'action' => 'hide'];

        $this->assertNull(ExperimentValidator::validateVariants(ExperimentValidator::normalizeVariants([['changes' => array_fill(0, 20, $change)]])));
        $this->assertSame('RebelABTesting_ErrorChange', ExperimentValidator::validateVariants(ExperimentValidator::normalizeVariants([['changes' => array_fill(0, 21, $change)]])));
    }

    public function testOnlyCssAndJavaScriptAreCode(): void
    {
        $changes = [['selector' => 'h1', 'action' => 'style', 'value' => 'color: red']];

        $this->assertFalse(ExperimentValidator::hasCode(ExperimentValidator::normalizeVariants([['changes' => $changes], ['css' => ' ', 'js' => "\n"]])));
        $this->assertTrue(ExperimentValidator::hasCode(ExperimentValidator::normalizeVariants([['changes' => $changes], ['css' => 'a{}']])));
        $this->assertTrue(ExperimentValidator::hasCode(ExperimentValidator::normalizeVariants([['js' => 'x()']])));
    }

    public function testAnExperimentHasOneToTenVariants(): void
    {
        $variant = ['name' => '', 'changes' => [], 'css' => '', 'js' => ''];

        $this->assertNull(ExperimentValidator::validateVariants([$variant]));
        $this->assertNull(ExperimentValidator::validateVariants(array_fill(0, 10, $variant)));
        $this->assertSame('RebelABTesting_ErrorVariants', ExperimentValidator::validateVariants([]));
        $this->assertSame('RebelABTesting_ErrorVariants', ExperimentValidator::validateVariants(array_fill(0, 11, $variant)));
    }
}
