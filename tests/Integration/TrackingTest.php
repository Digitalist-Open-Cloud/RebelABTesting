<?php

namespace Piwik\Plugins\RebelABTesting\tests\Integration;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\RebelABTesting\API;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;
use Piwik\Plugins\VisitsSummary\API as VisitsSummaryApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group RebelABTesting
 * @group Plugins
 */
class TrackingTest extends IntegrationTestCase
{
    private const DAY = '2024-03-05';
    private const ASSIGNMENT = '0123456789abcdef0123456789abcdef';

    /**
     * @var int
     */
    private $idExperiment;

    /**
     * @var int
     */
    private $visits = 0;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::createSuperUser();
        Fixture::createWebsite('2024-01-01 00:00:00');
        Fixture::createWebsite('2024-01-01 00:00:00');
        $this->idExperiment = API::getInstance()->insertExperiment(1, 'button', '', '', '2024-03-01', '2024-03-31', '', '');
        $this->visits = 0;
    }

    public function testAVisitIsLoggedForTheExperimentOfItsWebsite()
    {
        $this->trackVisit(1, ['sabt' => '2', 'sabi' => $this->idExperiment, 'sabn' => 'a name the browser made up', 'saba' => self::ASSIGNMENT]);

        $this->assertEquals(
            [['idsite' => 1, 'experiment_name' => 'button', 'idexperiment' => $this->idExperiment, 'variant' => 2, 'assignment_id' => self::ASSIGNMENT]],
            $this->getLog()
        );
    }

    public function testAnExperimentIsFoundByItsNameWhenTheIdIsMissing()
    {
        $this->trackVisit(1, ['sabt' => '1', 'sabn' => 'button']);

        $this->assertEquals('button', $this->getLog()[0]['experiment_name']);
        $this->assertEquals($this->idExperiment, $this->getLog()[0]['idexperiment']);
    }

    /**
     * @dataProvider getRequestsThatDoNotCount
     */
    public function testARequestThatIsNotForARunningExperimentOfTheWebsiteIsNotLogged(int $idSite, array $parameters, string $day)
    {
        $this->trackVisit($idSite, $parameters, $day);

        $this->assertSame([], $this->getLog());
    }

    public function getRequestsThatDoNotCount(): array
    {
        return [
            'no experiment' => [1, [], self::DAY],
            'unknown experiment' => [1, ['sabt' => '1', 'sabi' => 99, 'sabn' => 'made-up'], self::DAY],
            'experiment of another website' => [2, ['sabt' => '1', 'sabi' => 1, 'sabn' => 'button'], self::DAY],
            'a variant that does not exist' => [1, ['sabt' => '3', 'sabi' => 1], self::DAY],
            'after the experiment has ended' => [1, ['sabt' => '1', 'sabi' => 1], '2024-04-01'],
            'before the experiment starts' => [1, ['sabt' => '1', 'sabi' => 1], '2024-02-29'],
        ];
    }

    public function testTheVisitKeepsVariantAndExperiment()
    {
        $this->trackVisit(1, ['sabt' => '2', 'sabi' => $this->idExperiment, 'sabn' => 'button']);

        $this->assertEquals(
            ['abtest_variant' => 2, 'abtest_idexperiment' => $this->idExperiment],
            Db::fetchRow('SELECT abtest_variant, abtest_idexperiment FROM ' . Common::prefixTable('log_visit'))
        );
    }

    public function testValuesThatAreNotWellFormedAreNotKeptOnTheVisit()
    {
        $this->trackVisit(1, ['sabt' => "2' OR 1", 'sabi' => 'abc']);

        $this->assertSame(
            ['abtest_variant' => null, 'abtest_idexperiment' => null],
            Db::fetchRow('SELECT abtest_variant, abtest_idexperiment FROM ' . Common::prefixTable('log_visit'))
        );
    }

    public function testVisitsCanBeSegmentedByVariant()
    {
        $this->trackVisit(1, ['sabt' => '1', 'sabi' => $this->idExperiment]);
        $this->trackVisit(1, ['sabt' => '2', 'sabi' => $this->idExperiment]);
        $this->trackVisit(1, ['sabt' => '2', 'sabi' => $this->idExperiment]);
        $this->trackVisit(1, []);

        $visits = VisitsSummaryApi::getInstance()->get(1, 'day', self::DAY, 'experimentVariant==2;experimentId==' . $this->idExperiment);

        $this->assertEquals(2, $visits->getFirstRow()->getColumn('nb_visits'));
    }

    public function testTheReportsCountVisitsAndConversionsPerVariant()
    {
        GoalsApi::getInstance()->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');
        $original = ['sabt' => '1', 'sabi' => $this->idExperiment];
        $variant = ['sabt' => '2', 'sabi' => $this->idExperiment];

        $this->trackVisit(1, $original, self::DAY, ['/', '/pricing']);
        $this->trackVisit(1, $variant, self::DAY, ['/', '/thanks']);
        $this->trackVisit(1, $variant, self::DAY, ['/thanks']);
        $this->trackVisit(1, [], self::DAY, ['/thanks']);

        $api = API::getInstance();
        $this->assertSame(
            ['button' => ['nb_visits' => 3, 'variants' => ['1' => 1, '2' => 2]]],
            $this->flatten($api->getExperimentData(1, 'day', self::DAY), 'nb_visits', function ($idSubtable) use ($api) {
                return $api->getExperimentData(1, 'day', self::DAY, null, $idSubtable);
            })
        );
        $this->assertSame(
            ['button' => ['nb_conversions' => 2, 'variants' => ['2' => 2]]],
            $this->flatten($api->getExperimentGoalData(1, 'week', self::DAY), 'nb_conversions', function ($idSubtable) use ($api) {
                return $api->getExperimentGoalData(1, 'week', self::DAY, null, $idSubtable);
            })
        );
        $this->assertEquals(
            [
                ['experiment_name' => 'button', 'variant' => 1, 'nb_unique_visitors' => 1, 'nb_visits' => 1],
                ['experiment_name' => 'button', 'variant' => 2, 'nb_unique_visitors' => 2, 'nb_visits' => 2],
            ],
            $api->getExperimentReportData(1, 'day', self::DAY)
        );
        $this->assertSame(0, $api->getExperimentData(2, 'day', self::DAY)->getRowsCount());
    }

    /**
     * @param \Piwik\DataTable $table
     * @return array<string, array<string, mixed>> the metric of each experiment and of its variants
     */
    private function flatten($table, string $metric, callable $getSubtable): array
    {
        $result = [];
        foreach ($table->getRows() as $row) {
            $variants = [];
            foreach ($getSubtable($row->getIdSubDataTable())->getRows() as $variant) {
                $variants[(string) $variant->getColumn('label')] = (int) $variant->getColumn($metric);
            }
            ksort($variants);
            $result[$row->getColumn('label')] = [$metric => (int) $row->getColumn($metric), 'variants' => $variants];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $parameters the experiment parameters the tag adds to a request
     * @param string[] $paths
     */
    private function trackVisit(int $idSite, array $parameters, string $day = self::DAY, array $paths = ['/']): void
    {
        $tracker = Fixture::getTracker($idSite, $day . ' 10:00:00', true, true);
        $tracker->setTokenAuth(Fixture::getTokenAuth());
        $tracker->setNewVisitorId();
        // Matomo takes requests from one address for one visit.
        $tracker->setIp('10.0.0.' . ++$this->visits);
        foreach ($parameters as $name => $value) {
            $tracker->setCustomTrackingParameter($name, $value);
        }

        foreach ($paths as $minutes => $path) {
            $tracker->setForceVisitDateTime(date('Y-m-d H:i:s', strtotime($day . ' 10:00:00 UTC') + $minutes * 60));
            $tracker->setUrl('http://example.org' . $path);
            Fixture::checkResponse($tracker->doTrackPageView('Page'));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getLog(): array
    {
        return Db::fetchAll('SELECT DISTINCT idsite, experiment_name, idexperiment, variant, assignment_id FROM ' . Common::prefixTable(LogExperiment::TABLE));
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);

        $fixture->extraPluginsToLoad = ['RebelABTesting'];
    }
}
