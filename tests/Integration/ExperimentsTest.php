<?php

namespace Piwik\Plugins\RebelABTesting\tests\Integration;

use Exception;
use Piwik\Date;
use Piwik\NoAccessException;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\RebelABTesting\API;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group RebelABTesting
 * @group Plugins
 */
class ExperimentsTest extends IntegrationTestCase
{
    private const JS = "// greet\nif (a < b && c > \"d\") { alert('hi'); }";
    private const VARIANT = ['name' => '', 'changes' => [['selector' => 'h1', 'action' => 'text', 'value' => 'New']], 'redirect' => '', 'css' => '', 'js' => ''];

    /**
     * @var API
     */
    private $api;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::loadAllTranslations();
        Fixture::createWebsite('2024-01-01 00:00:00');
        Fixture::createWebsite('2024-01-01 00:00:00');
        FakeAccess::clearAccess(true);
        Date::$now = strtotime('2024-03-05 12:00:00 UTC');
        $this->api = API::getInstance();
    }

    public function tearDown(): void
    {
        Date::$now = null;

        parent::tearDown();
    }

    public function provideContainerConfig()
    {
        return ['Piwik\Access' => new FakeAccess()];
    }

    public function testAnExperimentIsStoredAndReadAsItWasWritten()
    {
        $changes = [['selector' => 'h1 > a', 'action' => 'text', 'value' => 'Buy "now" & <save>'], ['selector' => '.ad', 'action' => 'hide', 'value' => '']];
        $variants = [['name' => 'Green', 'changes' => $changes, 'redirect' => '', 'css' => 'h1 > a { color: green; }', 'js' => self::JS], ['name' => '', 'changes' => [], 'redirect' => '', 'css' => '', 'js' => 'b()']];

        $idGoal = GoalsApi::getInstance()->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');

        $id = $this->api->insertExperiment(1, 'Button, spring', 'We think "green" wins', 'About <the> button', '2024-03-01', '2024-03-31', $variants, 20, 'contains', ' /pricing ', ['type' => 'goal', 'idgoal' => $idGoal, 'category' => 'ignored'], 'ab', [], [['type' => 'bounce'], ['type' => 'event', 'category' => ' Video ', 'action' => 'Play'], ['type' => 'bounce'], ['type' => 'search']]);

        $experiment = $this->api->getExperiment($id, 1);
        $this->assertRegExpCompat('/^[0-9a-f]{32}$/', $experiment['preview_token']);
        $this->assertSame(['superUserLogin', true, ''], [$experiment['created_by'], $experiment['notify'], $experiment['notified']]);
        unset($experiment['preview_token'], $experiment['created_by'], $experiment['notify'], $experiment['notified']);
        $this->assertEquals([
            'id' => $id,
            'idsite' => 1,
            'name' => 'Button, spring',
            'hypothesis' => 'We think "green" wins',
            'description' => 'About <the> button',
            'from_date' => '2024-03-01',
            'to_date' => '2024-03-31',
            'stopped_at' => null,
            'traffic_share' => 20,
            'target_match' => 'contains',
            'target_value' => '/pricing',
            'exclude_match' => 'none',
            'exclude_value' => '',
            'confidence' => 95,
            'variants' => $variants,
            'primary_measure' => ['type' => 'goal', 'idgoal' => $idGoal],
            'guardrails' => [['type' => 'bounce'], ['type' => 'event', 'category' => 'Video', 'action' => 'Play']],
            'kind' => 'ab',
            'door' => null,
            'status' => 'running',
        ], $experiment);
    }

    /**
     * @dataProvider getMeasures
     */
    public function testAnExperimentCanBeJudgedBy(array $given, ?array $stored)
    {
        $id = $this->api->insertExperiment(1, 'judged', '', '', '2024-03-01', '2024-03-31', [self::VARIANT], 100, 'any', '', $given);

        $this->assertSame($stored, $this->api->getExperiment($id, 1)['primary_measure']);
    }

    public function getMeasures(): array
    {
        return [
            'nothing' => [[], null],
            'orders' => [['type' => 'orders'], ['type' => 'orders']],
            'revenue' => [['type' => 'revenue', 'idgoal' => 4], ['type' => 'revenue']],
            'bounces' => [['type' => 'bounce'], ['type' => 'bounce']],
            'site search' => [['type' => 'search'], ['type' => 'search']],
            'page views' => [['type' => 'pageviews'], ['type' => 'pageviews']],
            'visit length' => [['type' => 'visit_length'], ['type' => 'visit_length']],
            'any goal' => [['type' => 'conversions'], ['type' => 'conversions']],
            'total revenue' => [['type' => 'total_revenue'], ['type' => 'total_revenue']],
            'an event of a category' => [['type' => 'event', 'category' => 'Video'], ['type' => 'event', 'category' => 'Video', 'action' => '']],
            'an event of a category and action' => [['type' => 'event', 'category' => 'Video', 'action' => ' Play '], ['type' => 'event', 'category' => 'Video', 'action' => 'Play']],
        ];
    }

    public function testAnExperimentCanBeJudgedByTheRevenueOfAGoalAndLeaveOutPages()
    {
        $idGoal = GoalsApi::getInstance()->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');

        $id = $this->api->insertExperiment(1, 'judged', '', '', '2024-03-01', '2024-03-31', [self::VARIANT], 100, 'any', '', ['type' => 'goal_revenue', 'idgoal' => $idGoal], 'ab', [], [], 99, 'starts_with', ' https://example.org/checkout ');

        $experiment = $this->api->getExperiment($id, 1);
        $this->assertSame(['type' => 'goal_revenue', 'idgoal' => $idGoal], $experiment['primary_measure']);
        $this->assertSame([99, 'starts_with', 'https://example.org/checkout'], [$experiment['confidence'], $experiment['exclude_match'], $experiment['exclude_value']]);
    }

    public function testAFakeDoorIsStoredWithoutVariantsAndPrimaryGoal()
    {
        $door = ['selector' => '#menu', 'position' => 'append', 'element' => 'link', 'label' => 'Compare <plans>', 'css_class' => 'nav-link', 'message' => 'Coming "soon"'];

        $id = $this->api->insertExperiment(1, 'door', '', '', '2024-03-01', '2024-03-31', [['js' => 'ignored()']], 100, 'any', '', ['type' => 'bounce'], 'fake_door', $door, [['type' => 'bounce']]);

        $experiment = $this->api->getExperiment($id, 1);
        $this->assertSame(['fake_door', $door, [], null, []], [$experiment['kind'], $experiment['door'], $experiment['variants'], $experiment['primary_measure'], $experiment['guardrails']]);
    }

    public function testAWatchedElementIsStoredWithItsSelectorOnly()
    {
        $id = $this->api->insertExperiment(1, 'watch', '', '', '2024-03-01', '2024-03-31', [['js' => 'ignored()']], 100, 'any', '', ['type' => 'bounce'], 'watch', ['selector' => '.signup', 'label' => 'ignored']);

        $experiment = $this->api->getExperiment($id, 1);
        $this->assertSame(['watch', ['selector' => '.signup'], [], null], [$experiment['kind'], $experiment['door'], $experiment['variants'], $experiment['primary_measure']]);
    }

    public function testEachExperimentHasItsOwnSecretForPreviews()
    {
        $one = $this->api->getExperiment($this->add(1, 'one', '2024-03-01', '2024-03-31'), 1);
        $two = $this->api->getExperiment($this->add(1, 'two', '2024-03-01', '2024-03-31'), 1);
        $this->api->updateExperiment($one['id'], 1, 'renamed', '', '', '2024-03-01', '2024-03-31', [self::VARIANT]);

        $this->assertNotSame($one['preview_token'], $two['preview_token']);
        $this->assertSame($one['preview_token'], $this->api->getExperiment($one['id'], 1)['preview_token']);
    }

    public function testAnExperimentRunsForAllVisitorsOnAllPagesUnlessToldOtherwise()
    {
        $experiment = $this->api->getExperiment($this->add(1, 'button', '2024-03-01', '2024-03-31'), 1);

        $this->assertSame([100, 'any', ''], [$experiment['traffic_share'], $experiment['target_match'], $experiment['target_value']]);
    }

    public function testAnExperimentIsUpdated()
    {
        $id = $this->add(1, 'button', '2024-03-01', '2024-03-31');

        $this->api->updateExperiment($id, 1, 'link', 'h', 'd', '2024-03-05', '2024-04-05', [['css' => 'a{}'], ['js' => 'x()']], 50, 'equals', 'https://example.org/');

        $experiment = $this->api->getExperiment($id, 1);
        $this->assertSame(['link', '2024-03-05', '2024-04-05', 50, 'equals'], [$experiment['name'], $experiment['from_date'], $experiment['to_date'], $experiment['traffic_share'], $experiment['target_match']]);
        $this->assertSame(['a{}', ''], array_column($experiment['variants'], 'css'));
        $this->assertSame([[], []], array_column($experiment['variants'], 'changes'));
    }

    public function testAnExperimentIsDeleted()
    {
        $id = $this->add(1, 'button', '2024-03-01', '2024-03-31');

        $this->api->deleteExperiment($id, 1);

        $this->assertNull($this->api->getExperiment($id, 1));
    }

    /**
     * @dataProvider getDays
     */
    public function testTheStatusFollowsTheDaysOfTheExperiment(string $now, string $status)
    {
        $id = $this->add(1, 'button', '2024-03-01', '2024-03-31');
        Date::$now = strtotime($now . ' UTC');

        $this->assertSame($status, $this->api->getExperiment($id, 1)['status']);
    }

    public function getDays(): array
    {
        return [
            ['2024-02-29 23:59:59', 'planned'],
            ['2024-03-01 00:00:00', 'running'],
            ['2024-03-31 23:59:59', 'running'],
            ['2024-04-01 00:00:00', 'ended'],
        ];
    }

    public function testAStoppedExperimentHasEnded()
    {
        $id = $this->add(1, 'button', '2024-03-01', '2024-03-31');

        $this->api->stopExperiment($id, 1);

        $experiment = $this->api->getExperiment($id, 1);
        $this->assertSame('ended', $experiment['status']);
        $this->assertSame('2024-03-05 12:00:00', $experiment['stopped_at']);
    }

    /**
     * @dataProvider getInvalidExperiments
     */
    public function testAnInvalidExperimentIsRefused(array $change, string $message)
    {
        $this->add(1, 'running', '2024-03-01', '2024-03-31');
        $experiment = $change + ['name' => 'new', 'from' => '2024-05-01', 'to' => '2024-05-31', 'variants' => [self::VARIANT], 'share' => 100, 'match' => 'any', 'value' => '', 'measure' => [], 'kind' => 'ab', 'door' => [], 'confidence' => 95, 'exclude' => ['none', '']];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);

        $this->api->insertExperiment(1, $experiment['name'], '', '', $experiment['from'], $experiment['to'], $experiment['variants'], $experiment['share'], $experiment['match'], $experiment['value'], $experiment['measure'], $experiment['kind'], $experiment['door'], [], $experiment['confidence'], ...$experiment['exclude']);
    }

    public function getInvalidExperiments(): array
    {
        return [
            'no name' => [['name' => ' '], 'needs a name'],
            'name is taken' => [['name' => 'running'], 'experiment with this name'],
            'not a date' => [['from' => 'tomorrow'], 'YYYY-MM-DD'],
            'last day first' => [['from' => '2024-06-01'], 'must not be after'],
            'no variants' => [['variants' => []], 'between 1 and 10 variants'],
            'a link that runs code' => [['variants' => [['changes' => [['selector' => 'a', 'action' => 'link', 'value' => 'javascript:x()']]]]], 'A change needs'],
            'no visitors' => [['share' => 0], 'between 1 and 100'],
            'unknown comparison' => [['match' => 'sounds_like', 'value' => 'x'], 'Unknown way'],
            'nothing to compare with' => [['match' => 'contains'], 'address to compare'],
            'a goal the website does not have' => [['measure' => ['type' => 'goal', 'idgoal' => 99]], 'A measure is'],
            'an event without a category' => [['measure' => ['type' => 'event', 'action' => 'Play']], 'A measure is'],
            'an unknown measure' => [['measure' => ['type' => 'happiness']], 'A measure is'],
            'a watched element without a selector' => [['kind' => 'watch', 'door' => []], 'element to watch'],
            'an unknown way to leave out pages' => [['exclude' => ['sounds_like', 'x']], 'Unknown way'],
            'no pages to leave out' => [['exclude' => ['contains', '']], 'address to compare'],
            'a level of certainty that is not offered' => [['confidence' => 80], '90, 95 or 99'],
            'an unknown kind' => [['kind' => 'survey'], 'Unknown kind'],
            'a fake door without its fields' => [['kind' => 'fake_door', 'door' => ['label' => 'Export']], 'A fake door needs'],
            'a goal that is not a number' => [['measure' => ['type' => 'goal', 'idgoal' => '1 OR 1']], 'A measure is'],
        ];
    }

    public function testExperimentsCanRunAtTheSameTimeAndHaveTheSameNameOnAnotherWebsite()
    {
        $this->add(1, 'one', '2024-03-01', '2024-03-31');

        $this->assertGreaterThan(0, $this->add(1, 'two', '2024-03-01', '2024-03-31'));
        $this->assertGreaterThan(0, $this->add(2, 'one', '2024-03-01', '2024-03-31'));
    }

    /**
     * @dataProvider getAdminMethods
     */
    public function testOnlyAUserWhoCanWriteToTheWebsiteCanReadAndChangeItsExperiments(string $method, array $parameters)
    {
        $id = $this->add(2, 'theirs', '2024-03-01', '2024-03-31');
        FakeAccess::clearAccess(false, [1], [2]);

        try {
            $this->api->$method(...$parameters);
            $this->fail('The request was not refused.');
        } catch (NoAccessException $e) {
        }

        FakeAccess::clearAccess(true);
        $experiment = $this->api->getExperiment($id, 2);
        $this->assertSame(['theirs', 'running'], [$experiment['name'], $experiment['status']]);
        $this->assertCount(1, (new Experiments())->getAllForSite(2));
    }

    public function getAdminMethods(): array
    {
        return [
            'add' => ['insertExperiment', [2, 'mine', '', '', '2024-05-01', '2024-05-31', [self::VARIANT]]],
            'update' => ['updateExperiment', [1, 2, 'mine', '', '', '2024-03-01', '2024-03-31', [self::VARIANT]]],
            'stop' => ['stopExperiment', [1, 2]],
            'delete' => ['deleteExperiment', [1, 2]],
            'read' => ['getExperiment', [1, 2]],
        ];
    }

    public function testAUserWithWriteAccessCanRunExperimentsWithoutCode()
    {
        FakeAccess::clearAccess(false, [], [], 'writer', [1]);

        $id = $this->add(1, 'no code', '2024-03-01', '2024-03-31');
        $this->api->updateExperiment($id, 1, 'still no code', '', '', '2024-03-01', '2024-03-31', [['changes' => [['selector' => '.ad', 'action' => 'hide']]]]);
        $door = $this->api->insertExperiment(1, 'door', '', '', '2024-03-01', '2024-03-31', [], 100, 'any', '', [], 'fake_door', ['selector' => '#menu', 'label' => 'Export', 'message' => 'Soon']);
        $this->api->stopExperiment($door, 1);
        $this->api->deleteExperiment($door, 1);

        $experiment = $this->api->getExperiment($id, 1);
        $this->assertSame(['still no code', 'hide'], [$experiment['name'], $experiment['variants'][0]['changes'][0]['action']]);
        $this->assertCount(1, $this->api->getExperiments(1));
    }

    /**
     * @dataProvider getCode
     */
    public function testCssAndJavaScriptNeedAdminAccess(array $variant)
    {
        $id = $this->add(1, 'no code', '2024-03-01', '2024-03-31');
        FakeAccess::clearAccess(false, [], [], 'writer', [1]);

        foreach (['insert' => [1, 'code', '', '', '2024-05-01', '2024-05-31', [$variant]], 'update' => [$id, 1, 'code', '', '', '2024-03-01', '2024-03-31', [self::VARIANT, $variant]]] as $action => $parameters) {
            try {
                $this->api->{$action . 'Experiment'}(...$parameters);
                $this->fail("The $action was not refused.");
            } catch (NoAccessException $e) {
                $this->addToAssertionCount(1);
            }
        }

        FakeAccess::clearAccess(false, [1]);
        $this->assertGreaterThan(0, $this->api->insertExperiment(1, 'code', '', '', '2024-05-01', '2024-05-31', [$variant]));
        $this->assertSame(['no code', 'code'], array_column($this->api->getExperiments(1), 'name'));
    }

    public function getCode(): array
    {
        return [
            'JavaScript' => [['js' => 'x()']],
            'CSS' => [['css' => 'a{}']],
        ];
    }

    public function testAnExperimentWithCodeIsOnlyChangedByAnAdmin()
    {
        $id = $this->api->insertExperiment(1, 'code', '', '', '2024-03-01', '2024-03-31', [['js' => 'x()']]);
        FakeAccess::clearAccess(false, [], [], 'writer', [1]);

        try {
            $this->api->updateExperiment($id, 1, 'mine now', '', '', '2024-03-01', '2024-03-31', [self::VARIANT]);
            $this->fail('The update was not refused.');
        } catch (NoAccessException $e) {
        }

        $experiment = $this->api->getExperiment($id, 1);
        $this->assertSame(['code', 'x()'], [$experiment['name'], $experiment['variants'][0]['js']]);
        $this->assertTrue($this->api->getExperiments(1)[0]['has_code']);
    }

    public function testAnExperimentCannotBeChangedThroughAnotherWebsite()
    {
        $id = $this->add(2, 'theirs', '2024-03-01', '2024-03-31');
        FakeAccess::clearAccess(false, [1]);

        $this->api->updateExperiment($id, 1, 'mine', '', '', '2024-03-01', '2024-03-31', [['js' => 'evil()']]);
        $this->api->stopExperiment($id, 1);
        $this->api->deleteExperiment($id, 1);
        $read = $this->api->getExperiment($id, 1);

        FakeAccess::clearAccess(true);
        $this->assertNull($read);
        $this->assertSame(['theirs', 'running'], [$this->api->getExperiment($id, 2)['name'], $this->api->getExperiment($id, 2)['status']]);
    }

    public function testAViewerSeesTheExperimentsButNotWhatTheirVariantsDo()
    {
        $id = $this->add(1, 'button', '2024-03-01', '2024-03-31');
        FakeAccess::clearAccess(false, [], [1]);

        $this->assertSame(
            [['id' => $id, 'idsite' => 1, 'name' => 'button', 'from_date' => '2024-03-01', 'to_date' => '2024-03-31', 'kind' => 'ab', 'status' => 'running', 'has_code' => false]],
            $this->api->getExperiments(1)
        );
    }

    public function testTheReportsNeedViewAccess()
    {
        $id = $this->add(2, 'theirs', '2024-03-01', '2024-03-31');
        FakeAccess::clearAccess(false, [], [1]);

        $requests = [
            'getExperiments' => [2],
            'getExperimentResult' => [2, $id],
        ];
        foreach ($requests as $method => $parameters) {
            try {
                $this->api->$method(...$parameters);
                $this->fail("$method was not refused.");
            } catch (NoAccessException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function assertRegExpCompat(string $pattern, string $value): void
    {
        $this->assertSame(1, preg_match($pattern, $value));
    }

    private function add(int $idSite, string $name, string $from, string $to): int
    {
        return $this->api->insertExperiment($idSite, $name, '', '', $from, $to, [self::VARIANT]);
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);

        $fixture->extraPluginsToLoad = ['RebelABTesting'];
    }
}
