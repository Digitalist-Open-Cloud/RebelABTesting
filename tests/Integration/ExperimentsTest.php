<?php

namespace Piwik\Plugins\RebelABTesting\tests\Integration;

use Exception;
use Piwik\Common;
use Piwik\Db;
use Piwik\NoAccessException;
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
        $this->api = API::getInstance();
    }

    public function provideContainerConfig()
    {
        return ['Piwik\Access' => new FakeAccess()];
    }

    public function testAnExperimentIsStoredAndReadAsItWasWritten()
    {
        $id = $this->add(1, 'button', '2024-03-01', '2024-03-31', 'h1 > a { color: red; }', self::JS);

        $experiment = $this->api->getExperiment($id, 1);

        $this->assertSame('button', $experiment['name']);
        $this->assertSame('We think "green" wins', $experiment['hypothesis']);
        $this->assertSame('2024-03-01', $experiment['from_date']);
        $this->assertSame('2024-03-31', $experiment['to_date']);
        $this->assertSame('h1 > a { color: red; }', $experiment['css_insert']);
        $this->assertSame(self::JS, $experiment['js_insert']);
    }

    public function testAnExperimentStoredByAnOlderVersionIsDecoded()
    {
        Db::query(
            'INSERT INTO ' . Common::prefixTable(Experiments::TABLE) . ' (idsite, name, `from_date`, `to_date`, css_insert, js_insert) VALUES (?,?,?,?,?,?)',
            [1, 'old', '2024-03-01', '2024-03-31', '', 'alert(&quot;hi&quot;) &amp;&amp; x &lt; 1']
        );

        $this->assertSame('alert("hi") && x < 1', $this->api->getExperiment(1, 1)['js_insert']);
    }

    public function testAnExperimentIsUpdated()
    {
        $id = $this->add(1, 'button', '2024-03-01', '2024-03-31');

        $this->api->updateExperiment($id, 1, 'link', 'h', 'd', '2024-03-05', '2024-04-05', 'a{}', 'x()');

        $experiment = $this->api->getExperiment($id, 1);
        $this->assertSame(['link', '2024-03-05', '2024-04-05', 'x()'], [$experiment['name'], $experiment['from_date'], $experiment['to_date'], $experiment['js_insert']]);
    }

    public function testAnExperimentIsDeleted()
    {
        $id = $this->add(1, 'button', '2024-03-01', '2024-03-31');

        $this->api->deleteExperiment($id, 1);

        $this->assertNull($this->api->getExperiment($id, 1));
    }

    /**
     * @dataProvider getInvalidExperiments
     */
    public function testAnInvalidExperimentIsRefused(string $name, string $from, string $to, string $message)
    {
        $this->add(1, 'running', '2024-03-01', '2024-03-31');
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);

        $this->add(1, $name, $from, $to);
    }

    public function getInvalidExperiments(): array
    {
        return [
            'no name' => [' ', '2024-05-01', '2024-05-31', 'needs a name'],
            'comma in the name' => ['a,b', '2024-05-01', '2024-05-31', 'comma'],
            'not a date' => ['new', 'tomorrow', '2024-05-31', 'YYYY-MM-DD'],
            'last day first' => ['new', '2024-05-31', '2024-05-01', 'must not be after'],
            'another experiment runs then' => ['new', '2024-03-31', '2024-04-30', 'Only one experiment'],
            'name is taken' => ['running', '2024-05-01', '2024-05-31', 'experiment with this name'],
        ];
    }

    public function testAnExperimentMayKeepItsOwnPeriodWhenUpdated()
    {
        $id = $this->add(1, 'button', '2024-03-01', '2024-03-31');

        $this->api->updateExperiment($id, 1, 'button', '', '', '2024-03-01', '2024-04-15', '', '');

        $this->assertSame('2024-04-15', $this->api->getExperiment($id, 1)['to_date']);
    }

    public function testWebsitesCanHaveExperimentsOfTheSameNameAtTheSameTime()
    {
        $this->add(1, 'one', '2024-03-01', '2024-03-31');

        $this->assertGreaterThan(0, $this->add(2, 'one', '2024-03-01', '2024-03-31'));
    }

    /**
     * @dataProvider getWrites
     */
    public function testOnlyAnAdminOfTheWebsiteCanChangeItsExperiments(string $method, array $parameters)
    {
        $id = $this->add(2, 'theirs', '2024-03-01', '2024-03-31', '', 'secret()');
        FakeAccess::clearAccess(false, [1], [2]);

        try {
            $this->api->$method(...$parameters);
            $this->fail('The request was not refused.');
        } catch (NoAccessException $e) {
        }

        FakeAccess::clearAccess(true);
        $this->assertSame('theirs', $this->api->getExperiment($id, 2)['name']);
        $this->assertCount(1, (new Experiments())->getAllForSite(2));
    }

    public function getWrites(): array
    {
        return [
            'add' => ['insertExperiment', [2, 'mine', '', '', '2024-05-01', '2024-05-31', '', '']],
            'update' => ['updateExperiment', [1, 2, 'mine', '', '', '2024-03-01', '2024-03-31', '', '']],
            'delete' => ['deleteExperiment', [1, 2]],
            'read' => ['getExperiment', [1, 2]],
        ];
    }

    public function testAnExperimentCannotBeChangedThroughAnotherWebsite()
    {
        $id = $this->add(2, 'theirs', '2024-03-01', '2024-03-31');
        FakeAccess::clearAccess(false, [1]);

        $this->api->updateExperiment($id, 1, 'mine', '', '', '2024-03-01', '2024-03-31', '', 'evil()');
        $this->api->deleteExperiment($id, 1);
        $read = $this->api->getExperiment($id, 1);

        FakeAccess::clearAccess(true);
        $this->assertNull($read);
        $this->assertSame('theirs', $this->api->getExperiment($id, 2)['name']);
    }

    public function testAViewerGetsOnlyWhatTheTagNeeds()
    {
        $id = $this->add(1, 'button', '2024-03-01', '2024-03-31');
        FakeAccess::clearAccess(false, [], [1]);

        $this->assertSame(
            ['found', 'name', 'from_date', 'to_date', 'css_insert', 'js_insert'],
            array_keys($this->api->getExperimentPublic($id, 1))
        );
    }

    public function testTheReportsNeedViewAccess()
    {
        FakeAccess::clearAccess(false, [], [1]);

        foreach (['getExperimentData', 'getExperimentGoalData', 'getExperimentUniqueVisitorData', 'getExperimentReportData', 'getExperimentPublic'] as $method) {
            try {
                $method === 'getExperimentPublic' ? $this->api->$method(1, 2) : $this->api->$method(2, 'day', '2024-03-05');
                $this->fail("$method was not refused.");
            } catch (NoAccessException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function add(int $idSite, string $name, string $from, string $to, string $css = '', string $js = ''): int
    {
        return $this->api->insertExperiment($idSite, $name, 'We think "green" wins', 'About the button', $from, $to, $css, $js);
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);

        $fixture->extraPluginsToLoad = ['RebelABTesting'];
    }
}
