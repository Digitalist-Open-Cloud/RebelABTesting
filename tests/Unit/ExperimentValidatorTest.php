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

    public function testVariantsKeepOnlyNameCssAndJavaScript(): void
    {
        $this->assertSame(
            [['name' => 'Green', 'css' => 'a{}', 'js' => ''], ['name' => '', 'css' => '', 'js' => 'x()']],
            ExperimentValidator::normalizeVariants([['name' => ' Green ', 'css' => 'a{}', 'weight' => 9], 'text', ['js' => 'x()']])
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

    public function testAnExperimentHasOneToTenVariants(): void
    {
        $variant = ['name' => '', 'css' => '', 'js' => ''];

        $this->assertNull(ExperimentValidator::validateVariants([$variant]));
        $this->assertNull(ExperimentValidator::validateVariants(array_fill(0, 10, $variant)));
        $this->assertSame('RebelABTesting_ErrorVariants', ExperimentValidator::validateVariants([]));
        $this->assertSame('RebelABTesting_ErrorVariants', ExperimentValidator::validateVariants(array_fill(0, 11, $variant)));
    }
}
