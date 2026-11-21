<?php

namespace Piwik\Plugins\RebelABTesting\tests\Integration;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Db;
use Piwik\LogDeleter;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\PrivacyManager\Model\DataSubjects;
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

        Fixture::loadAllTranslations();
        Fixture::createSuperUser();
        Fixture::createWebsite('2024-01-01 00:00:00');
        Fixture::createWebsite('2024-01-01 00:00:00');
        $this->idExperiment = $this->addExperiment('button', 2);
        $this->visits = 0;
    }

    public function testAVisitIsLoggedOnceForEachExperimentItTakesPartIn()
    {
        $other = $this->addExperiment('headline', 1);

        $this->trackVisit(1, [$this->idExperiment => 3, $other => 1], self::DAY, ['/', '/pricing', '/thanks']);

        $this->assertEquals(
            [
                ['idsite' => 1, 'experiment_name' => 'button', 'idexperiment' => $this->idExperiment, 'variant' => 3, 'assignment_id' => self::ASSIGNMENT],
                ['idsite' => 1, 'experiment_name' => 'headline', 'idexperiment' => $other, 'variant' => 1, 'assignment_id' => self::ASSIGNMENT],
            ],
            $this->getLog()
        );
    }

    /**
     * @dataProvider getRequestsThatDoNotCount
     */
    public function testARequestThatIsNotForARunningExperimentOfTheWebsiteIsNotLogged(int $idSite, string $parameter, string $day)
    {
        $this->trackVisit($idSite, $parameter, $day);

        $this->assertSame([], $this->getLog());
    }

    public function getRequestsThatDoNotCount(): array
    {
        return [
            'no experiment' => [1, '', self::DAY],
            'unknown experiment' => [1, '99-1-' . self::ASSIGNMENT, self::DAY],
            'experiment of another website' => [2, '1-1-' . self::ASSIGNMENT, self::DAY],
            'a variant the experiment does not have' => [1, '1-4-' . self::ASSIGNMENT, self::DAY],
            'not well-formed' => [1, "1-2' OR 1-" . self::ASSIGNMENT, self::DAY],
            'after the experiment has ended' => [1, '1-1-' . self::ASSIGNMENT, '2024-04-01'],
            'before the experiment starts' => [1, '1-1-' . self::ASSIGNMENT, '2024-02-29'],
        ];
    }

    public function testAStoppedExperimentIsNoLongerLogged()
    {
        Date::$now = strtotime('2024-03-04 12:00:00 UTC');
        API::getInstance()->stopExperiment($this->idExperiment, 1);
        Date::$now = null;

        $this->trackVisit(1, [$this->idExperiment => 1]);

        $this->assertSame([], $this->getLog());
    }

    public function testVisitsCanBeSegmentedByExperimentAndVariant()
    {
        $other = $this->addExperiment('headline', 1);
        $this->trackVisit(1, [$this->idExperiment => 1]);
        $this->trackVisit(1, [$this->idExperiment => 2, $other => 1]);
        $this->trackVisit(1, [$this->idExperiment => 2, $other => 2]);
        $this->trackVisit(1, [$other => 2]);
        $this->trackVisit(1, []);

        $visits = function (string $segment): int {
            return (int) VisitsSummaryApi::getInstance()->get(1, 'day', self::DAY, $segment)->getFirstRow()->getColumn('nb_visits');
        };

        $this->assertSame(2, $visits('experimentId==' . $this->idExperiment . ';experimentVariant==2'));
        $this->assertSame(3, $visits('experimentId==' . $this->idExperiment));
        $this->assertSame(3, $visits('experimentId==' . $other));
        $this->assertSame(5, $visits(''));
    }

    public function testTheResultComparesTheVariantsWithTheOriginal()
    {
        GoalsApi::getInstance()->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');
        foreach ([1 => [40, 8], 2 => [40, 20], 3 => [10, 1]] as $variant => [$visits, $converted]) {
            for ($visit = 0; $visit < $visits; $visit++) {
                $this->trackVisit(1, [$this->idExperiment => $variant], self::DAY, $visit < $converted ? ['/', '/thanks', '/thanks?again'] : ['/']);
            }
        }
        $this->trackVisit(1, [], self::DAY, ['/thanks']);

        $result = API::getInstance()->getExperimentResult(1, $this->idExperiment);

        $this->assertSame(
            [['variant' => 1, 'name' => 'Original', 'nb_visits' => 40], ['variant' => 2, 'name' => 'Green', 'nb_visits' => 40], ['variant' => 3, 'name' => 'Variant 2', 'nb_visits' => 10]],
            $result['variants']
        );
        $this->assertSame([['Thanks', false]], array_map(null, array_column($result['goals'], 'name'), array_column($result['goals'], 'is_primary')));

        [$original, $green, $second] = $result['goals'][0]['variants'];
        $this->assertSame([8, 0.2, null, null, null], [$original['nb_visits_converted'], $original['conversion_rate'], $original['uplift'], $original['chance_to_beat_original'], $original['verdict']]);
        $this->assertSame([20, 0.5, 'better'], [$green['nb_visits_converted'], $green['conversion_rate'], $green['verdict']]);
        $this->assertEqualsWithDelta(1.5, $green['uplift'], 0.0001);
        $this->assertGreaterThan(0.95, $green['chance_to_beat_original']);
        $this->assertSame([1, 'not_enough_data', null], [$second['nb_visits_converted'], $second['verdict'], $second['chance_to_beat_original']]);
    }

    public function testThePrimaryGoalComesFirstAlsoWithoutConversions()
    {
        $goals = GoalsApi::getInstance();
        $goals->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');
        $idPrimary = $goals->addGoal(1, 'Signed up', 'url', 'welcome', 'contains');
        $id = API::getInstance()->insertExperiment(1, 'judged', '', '', '2024-03-01', '2024-03-31', [['js' => '']], 100, 'any', '', (string) $idPrimary);
        $this->trackVisit(1, [$id => 2], self::DAY, ['/', '/thanks']);

        $result = API::getInstance()->getExperimentResult(1, $id);

        $this->assertSame(
            [['Signed up', true, 0], ['Thanks', false, 1]],
            array_map(function (array $goal): array {
                return [$goal['name'], $goal['is_primary'], $goal['variants'][1]['nb_visits_converted']];
            }, $result['goals'])
        );
    }

    public function testTheReportsCountVisitsAndConversionsPerVariant()
    {
        GoalsApi::getInstance()->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');

        $this->trackVisit(1, [$this->idExperiment => 1], self::DAY, ['/', '/pricing']);
        $this->trackVisit(1, [$this->idExperiment => 2], self::DAY, ['/', '/thanks']);
        $this->trackVisit(1, [$this->idExperiment => 2], self::DAY, ['/thanks']);
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
        $this->assertSame(0, $api->getExperimentData(2, 'day', self::DAY)->getRowsCount());
    }

    public function testTheLogOfAVisitIsDeletedWithTheVisit()
    {
        $this->trackVisit(1, [$this->idExperiment => 1]);
        $this->trackVisit(1, [$this->idExperiment => 2]);
        $idVisit = (int) Db::fetchOne('SELECT idvisit FROM ' . Common::prefixTable(LogExperiment::TABLE) . ' WHERE variant = 2');

        StaticContainer::get(DataSubjects::class)->deleteDataSubjects([['idsite' => 1, 'idvisit' => $idVisit]]);

        $this->assertEquals([1], array_column($this->getLog(), 'variant'));
    }

    public function testTheLogIsDeletedWithOldVisits()
    {
        $this->trackVisit(1, [$this->idExperiment => 1], '2024-03-02');
        $this->trackVisit(1, [$this->idExperiment => 2], self::DAY);

        StaticContainer::get(LogDeleter::class)->deleteVisitsFor('2024-03-01 00:00:00', '2024-03-03 00:00:00', 1);

        $this->assertEquals([2], array_column($this->getLog(), 'variant'));
    }

    private function addExperiment(string $name, int $variants): int
    {
        $list = array_slice([['name' => 'Green', 'css' => '', 'js' => ''], ['name' => '', 'css' => '', 'js' => '']], 0, $variants);

        return API::getInstance()->insertExperiment(1, $name, '', '', '2024-03-01', '2024-03-31', $list);
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
     * @param array<int, int>|string $experiments the variant by experiment, or the parameter as the browser would send it
     * @param string[] $paths
     */
    private function trackVisit(int $idSite, $experiments, string $day = self::DAY, array $paths = ['/']): void
    {
        if (is_array($experiments)) {
            $entries = [];
            foreach ($experiments as $idExperiment => $variant) {
                $entries[] = $idExperiment . '-' . $variant . '-' . self::ASSIGNMENT;
            }
            $experiments = implode(',', $entries);
        }

        $tracker = Fixture::getTracker($idSite, $day . ' 10:00:00', true, true);
        $tracker->setTokenAuth(Fixture::getTokenAuth());
        $tracker->setNewVisitorId();
        // Matomo takes requests from one address for one visit.
        $tracker->setIp('10.0.' . intdiv($this->visits, 250) . '.' . (1 + $this->visits++ % 250));
        if ($experiments !== '') {
            $tracker->setCustomTrackingParameter('rabt', $experiments);
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
        return Db::fetchAll('SELECT idsite, experiment_name, idexperiment, variant, assignment_id FROM ' . Common::prefixTable(LogExperiment::TABLE) . ' ORDER BY idexperiment, idlog');
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);

        $fixture->extraPluginsToLoad = ['RebelABTesting'];
    }
}
