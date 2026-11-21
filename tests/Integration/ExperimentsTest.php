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
    private const VARIANT = ['name' => '', 'css' => '', 'js' => ''];

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
        $variants = [['name' => 'Green', 'css' => 'h1 > a { color: green; }', 'js' => self::JS], ['name' => '', 'css' => '', 'js' => 'b()']];

        $idGoal = GoalsApi::getInstance()->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');

        $id = $this->api->insertExperiment(1, 'Button, spring', 'We think "green" wins', 'About <the> button', '2024-03-01', '2024-03-31', $variants, 20, 'contains', ' /pricing ', (string) $idGoal);

        $experiment = $this->api->getExperiment($id, 1);
        $this->assertRegExpCompat('/^[0-9a-f]{32}$/', $experiment['preview_token']);
        unset($experiment['preview_token']);
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
            'variants' => $variants,
            'primary_goal' => $idGoal,
            'status' => 'running',
        ], $experiment);
    }

    public function testThePrimaryGoalCanBeEcommerceOrdersOrNone()
    {
        $orders = $this->api->insertExperiment(1, 'orders', '', '', '2024-03-01', '2024-03-31', [self::VARIANT], 100, 'any', '', 'ecommerceOrder');
        $none = $this->add(1, 'none', '2024-03-01', '2024-03-31');

        $this->assertSame(0, $this->api->getExperiment($orders, 1)['primary_goal']);
        $this->assertNull($this->api->getExperiment($none, 1)['primary_goal']);
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
        $experiment = $change + ['name' => 'new', 'from' => '2024-05-01', 'to' => '2024-05-31', 'variants' => [self::VARIANT], 'share' => 100, 'match' => 'any', 'value' => '', 'goal' => ''];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);

        $this->api->insertExperiment(1, $experiment['name'], '', '', $experiment['from'], $experiment['to'], $experiment['variants'], $experiment['share'], $experiment['match'], $experiment['value'], $experiment['goal']);
    }

    public function getInvalidExperiments(): array
    {
        return [
            'no name' => [['name' => ' '], 'needs a name'],
            'name is taken' => [['name' => 'running'], 'experiment with this name'],
            'not a date' => [['from' => 'tomorrow'], 'YYYY-MM-DD'],
            'last day first' => [['from' => '2024-06-01'], 'must not be after'],
            'no variants' => [['variants' => []], 'between 1 and 10 variants'],
            'no visitors' => [['share' => 0], 'between 1 and 100'],
            'unknown comparison' => [['match' => 'sounds_like', 'value' => 'x'], 'Unknown way'],
            'nothing to compare with' => [['match' => 'contains'], 'address to compare'],
            'a goal the website does not have' => [['goal' => '99'], 'not a goal of this website'],
            'a goal that is not a number' => [['goal' => '1 OR 1'], 'not a goal of this website'],
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
    public function testOnlyAnAdminOfTheWebsiteCanReadAndChangeItsExperiments(string $method, array $parameters)
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
            [['id' => $id, 'idsite' => 1, 'name' => 'button', 'from_date' => '2024-03-01', 'to_date' => '2024-03-31', 'status' => 'running']],
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
            'getExperimentData' => [2, 'day', '2024-03-05'],
            'getExperimentGoalData' => [2, 'day', '2024-03-05'],
            'getExperimentUniqueVisitorData' => [2, 'day', '2024-03-05'],
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
