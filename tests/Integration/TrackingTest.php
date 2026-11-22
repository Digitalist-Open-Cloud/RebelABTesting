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
            array_map(function (array $variant): array {
                return array_diff_key($variant, ['nb_visitors' => 0]);
            }, $result['variants'])
        );
        $this->assertSame([['Goal: Thanks', 'other']], array_map(null, array_column($result['measures'], 'name'), array_column($result['measures'], 'role')));

        [$original, $green, $second] = $result['measures'][0]['variants'];
        $this->assertSame([8, 0.2, null, null, null], [$original['nb_visits_with_outcome'], $original['value'], $original['uplift'], $original['chance_to_be_better'], $original['verdict']]);
        $this->assertSame([20, 0.5, 'better'], [$green['nb_visits_with_outcome'], $green['value'], $green['verdict']]);
        $this->assertEqualsWithDelta(1.5, $green['uplift'], 0.0001);
        $this->assertGreaterThan(0.95, $green['chance_to_be_better']);
        $this->assertSame([1, 'not_enough_data', null], [$second['nb_visits_with_outcome'], $second['verdict'], $second['chance_to_be_better']]);
    }

    public function testThePrimaryGoalComesFirstAlsoWithoutConversions()
    {
        $goals = GoalsApi::getInstance();
        $goals->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');
        $idPrimary = $goals->addGoal(1, 'Signed up', 'url', 'welcome', 'contains');
        $id = API::getInstance()->insertExperiment(1, 'judged', '', '', '2024-03-01', '2024-03-31', [['js' => '']], 100, 'any', '', ['type' => 'goal', 'idgoal' => $idPrimary]);
        $this->trackVisit(1, [$id => 2], self::DAY, ['/', '/thanks']);

        $result = API::getInstance()->getExperimentResult(1, $id);

        $this->assertSame(
            [['Goal: Signed up', 'primary', 0], ['Goal: Thanks', 'other', 1]],
            array_map(function (array $measure): array {
                return [$measure['name'], $measure['role'], $measure['variants'][1]['nb_visits_with_outcome']];
            }, $result['measures'])
        );
    }

    public function testAnExperimentIsJudgedByWhatItsVisitsDo()
    {
        $bounce = ['type' => 'bounce'];
        $search = ['type' => 'search'];
        $event = ['type' => 'event', 'category' => 'Video', 'action' => 'Play'];
        $id = API::getInstance()->insertExperiment(1, 'judged', '', '', '2024-03-01', '2024-03-31', [['js' => '']], 100, 'any', '', $bounce, 'ab', [], [$search, $event]);

        for ($visit = 0; $visit < 40; $visit++) {
            // 30 of the original's visits bounce, and 10 of the variant's.
            $this->trackVisit(1, [$id => 1], self::DAY, $visit < 30 ? ['/'] : ['/', '/more']);
            $this->trackVisit(1, [$id => 2], self::DAY, $visit < 10 ? ['/'] : ['/', '/more'], 0, [], $visit < 10 ? '' : ($visit < 14 ? 'search' : ($visit < 30 ? 'play' : '')));
        }

        $measures = API::getInstance()->getExperimentResult(1, $id)['measures'];

        $this->assertSame(
            [['Bounces', 'primary', true], ['Visits that search the website', 'guardrail', true], ['Event: Video / Play', 'guardrail', false]],
            array_map(function (array $measure): array {
                return [$measure['name'], $measure['role'], $measure['is_lower_better']];
            }, $measures)
        );

        [$original, $variant] = $measures[0]['variants'];
        $this->assertSame([30, 0.75, 10, 0.25, 'better'], [$original['nb_visits_with_outcome'], $original['value'], $variant['nb_visits_with_outcome'], $variant['value'], $variant['verdict']]);
        $this->assertGreaterThan(0.95, $variant['chance_to_be_better']);
        $this->assertLessThan(0, $variant['uplift']);

        $this->assertSame([0, 4], array_column($measures[1]['variants'], 'nb_visits_with_outcome'));
        $this->assertSame([0, 16], array_column($measures[2]['variants'], 'nb_visits_with_outcome'));
    }

    public function testRevenuePerVisitComparesTheAmounts()
    {
        Db::query('UPDATE ' . Common::prefixTable('site') . ' SET ecommerce = 1 WHERE idsite = 1');
        \Piwik\Site::clearCache();
        $id = API::getInstance()->insertExperiment(1, 'revenue', '', '', '2024-03-01', '2024-03-31', [['js' => '']], 100, 'any', '', ['type' => 'revenue']);

        for ($visit = 0; $visit < 40; $visit++) {
            // Both sell to half of their visits. The variant sells for twice as much.
            $this->trackVisit(1, [$id => 1], self::DAY, ['/'], 0, [], $visit % 2 ? 'order:' . (90 + $visit % 5) : '');
            $this->trackVisit(1, [$id => 2], self::DAY, ['/'], 0, [], $visit % 2 ? 'order:' . (190 + $visit % 5) : '');
        }

        $result = API::getInstance()->getExperimentResult(1, $id);
        $revenue = $result['measures'][0];
        [$original, $variant] = $revenue['variants'];

        $this->assertSame(['Revenue per visit', 'primary', true], [$revenue['name'], $revenue['role'], $revenue['is_amount']]);
        $this->assertSame([20, 20], [$original['nb_visits_with_outcome'], $variant['nb_visits_with_outcome']]);
        $this->assertEqualsWithDelta(46.0, $original['value'], 0.01);
        $this->assertEqualsWithDelta(96.0, $variant['value'], 0.01);
        $this->assertSame('better', $variant['verdict']);
    }

    /**
     * @dataProvider getSplits
     */
    public function testTheResultTellsWhetherTheVisitorsAreSplitEvenly(int $original, int $variant, ?bool $isEven)
    {
        $id = $this->addExperiment('split', 1);
        foreach ([1 => $original, 2 => $variant] as $number => $visitors) {
            for ($visitor = 0; $visitor < $visitors; $visitor++) {
                $this->trackVisit(1, $id . '-' . $number . '-' . md5($number . '-' . $visitor));
            }
        }
        // A visitor who comes back is still one visitor.
        $this->trackVisit(1, $id . '-1-' . md5('1-0'), '2024-03-06');

        $result = API::getInstance()->getExperimentResult(1, $id);

        $this->assertSame($isEven, $result['is_split_even']);
        $this->assertSame([$original, $original + 1], [$result['variants'][0]['nb_visitors'], $result['variants'][0]['nb_visits']]);
    }

    public function getSplits(): array
    {
        return [
            'too few visitors to tell' => [20, 5, null],
            'as even as chance makes it' => [50, 40, true],
            'far from even' => [90, 30, false],
        ];
    }

    public function testAFakeDoorCountsTheVisitsItWasShownToAndTheirClicks()
    {
        $door = ['selector' => '#menu', 'label' => 'Export', 'message' => 'Soon'];
        $id = API::getInstance()->insertExperiment(1, 'door', '', '', '2024-03-01', '2024-03-31', [], 100, 'any', '', [], 'fake_door', $door);
        $assigned = $id . '-2-' . self::ASSIGNMENT;

        $this->trackVisit(1, $assigned, self::DAY, ['/', '/pricing']);
        $this->trackVisit(1, $assigned, self::DAY, ['/'], 0, ['rabtd' => $id . '-s']);
        $this->trackVisit(1, $assigned, self::DAY, ['/', '/pricing'], 0, ['rabtd' => $id . '-s']);
        $this->trackVisit(1, $assigned, self::DAY, ['/', '/pricing', '/pricing'], 0, [0 => ['rabtd' => $id . '-s'], 1 => ['rabtd' => $id . '-c'], 2 => ['rabtd' => $id . '-c']]);
        $this->trackVisit(1, $assigned, self::DAY, ['/'], 0, ['rabtd' => $id . '-c']);
        $this->trackVisit(1, $id . '-1-' . self::ASSIGNMENT, self::DAY, ['/'], 0, ['rabtd' => $id . '-s']);
        $this->trackVisit(1, $assigned, self::DAY, ['/'], 0, ['rabtd' => $this->idExperiment . '-c']);

        $result = API::getInstance()->getExperimentResult(1, $id);

        $this->assertSame([4, 2, 0.5], [$result['nb_visits'], $result['nb_clicks'], $result['click_rate']]);
        $this->assertLessThan(0.5, $result['click_rate_range'][0]);
        $this->assertGreaterThan(0.5, $result['click_rate_range'][1]);
    }

    public function testAWatchedElementCountsTheVisitsThatSawItAndTheirClicks()
    {
        $id = API::getInstance()->insertExperiment(1, 'watch', '', '', '2024-03-01', '2024-03-31', [], 100, 'any', '', [], 'watch', ['selector' => '.signup']);
        $assigned = $id . '-2-' . self::ASSIGNMENT;

        $this->trackVisit(1, $assigned);
        $this->trackVisit(1, $assigned, self::DAY, ['/'], 0, ['rabtd' => $id . '-s']);
        $this->trackVisit(1, $assigned, self::DAY, ['/', '/pricing'], 0, [0 => ['rabtd' => $id . '-s'], 1 => ['rabtd' => $id . '-c']]);

        $result = API::getInstance()->getExperimentResult(1, $id);

        $this->assertSame([2, 1, 0.5], [$result['nb_visits'], $result['nb_clicks'], $result['click_rate']]);
    }

    public function testASignalForAFakeDoorDoesNotClickAnABTest()
    {
        $this->trackVisit(1, [$this->idExperiment => 2], self::DAY, ['/'], 0, ['rabtd' => $this->idExperiment . '-c']);

        $this->assertSame('0', (string) Db::fetchOne('SELECT SUM(clicked) FROM ' . Common::prefixTable(LogExperiment::TABLE)));
    }

    public function testAConversionBeforeTheVisitWasInTheExperimentDoesNotCount()
    {
        GoalsApi::getInstance()->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');
        $this->trackVisit(1, [$this->idExperiment => 2], self::DAY, ['/thanks', '/pricing'], 1);
        $this->trackVisit(1, [$this->idExperiment => 2], self::DAY, ['/pricing', '/thanks']);
        $this->trackVisit(1, [$this->idExperiment => 2], self::DAY, ['/thanks']);

        $api = API::getInstance();
        $result = $api->getExperimentResult(1, $this->idExperiment);

        $this->assertSame(3, $result['variants'][1]['nb_visits']);
        $this->assertSame(2, $result['measures'][0]['variants'][1]['nb_visits_with_outcome']);
        $this->assertSame(
            ['button' => ['nb_visits_converted' => 2, 'variants' => ['2' => 2]]],
            $this->flatten($api->getExperimentGoalData(1, 'day', self::DAY), 'nb_visits_converted', function ($idSubtable) use ($api) {
                return $api->getExperimentGoalData(1, 'day', self::DAY, null, $idSubtable);
            })
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
     * @param int $exposedFrom the page view from which the browser sends the experiments
     * @param array<int|string, mixed> $extra more parameters, for every page view or by the number of the page view
     * @param string $then what the visit does after its page views: "search", "play" a video, or an "order:<amount>"
     */
    private function trackVisit(int $idSite, $experiments, string $day = self::DAY, array $paths = ['/'], int $exposedFrom = 0, array $extra = [], string $then = ''): void
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

        foreach ($paths as $minutes => $path) {
            if ($experiments !== '' && $minutes >= $exposedFrom) {
                $tracker->setCustomTrackingParameter('rabt', $experiments);
            }
            foreach (isset($extra[0]) ? ($extra[$minutes] ?? []) : $extra as $name => $value) {
                $tracker->setCustomTrackingParameter($name, $value);
            }
            $tracker->setForceVisitDateTime(date('Y-m-d H:i:s', strtotime($day . ' 10:00:00 UTC') + $minutes * 60));
            $tracker->setUrl('http://example.org' . $path);
            Fixture::checkResponse($tracker->doTrackPageView('Page'));
        }

        $tracker->setForceVisitDateTime(date('Y-m-d H:i:s', strtotime($day . ' 10:00:00 UTC') + count($paths) * 60));
        if ($then === 'search') {
            Fixture::checkResponse($tracker->doTrackSiteSearch('opening hours'));
        } elseif ($then === 'play') {
            Fixture::checkResponse($tracker->doTrackEvent('Video', 'Play', 'Intro'));
        } elseif (strpos($then, 'order:') === 0) {
            Fixture::checkResponse($tracker->doTrackEcommerceOrder('order-' . $this->visits, (float) substr($then, 6)));
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
