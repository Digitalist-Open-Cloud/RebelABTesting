<?php

namespace Piwik\Plugins\RebelExperiments\tests\Integration;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugin\Manager;
use Piwik\Plugins\RebelExperiments\API;
use Piwik\Plugins\RebelExperiments\Measures;
use Piwik\Plugins\RebelFunnels\API as FunnelsApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Experiments judged by what RebelFunnels and UserFeedback know about a visit.
 *
 * @group RebelExperiments
 * @group Plugins
 */
class OtherPluginsTest extends IntegrationTestCase
{
    private const DAY = '2024-03-05';
    private const FORM = [
        ['type' => 'radio-group', 'name' => 'clear', 'label' => 'Was the page clear?', 'values' => [['label' => 'Yes', 'value' => 'y'], ['label' => 'No', 'value' => 'n']]],
        ['type' => 'textarea', 'name' => 'more', 'label' => 'Anything else?'],
    ];

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
        $this->visits = 0;
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->extraPluginsToLoad = array_values(array_filter(['RebelFunnels', 'UserFeedback'], function (string $plugin): bool {
            return is_dir(PIWIK_INCLUDE_PATH . '/plugins/' . $plugin);
        }));
    }

    public function testAnExperimentIsJudgedByTheVisitsThatCompleteAFunnel()
    {
        $this->needs('RebelFunnels');
        $idFunnel = $this->addFunnel();
        $id = $this->addExperiment(['type' => 'funnel', 'idfunnel' => $idFunnel]);

        for ($visit = 0; $visit < 40; $visit++) {
            $this->trackVisit([$id => 1], $visit < 10 ? ['/cart', '/thanks'] : ($visit < 20 ? ['/cart'] : ['/thanks']));
            $this->trackVisit([$id => 2], $visit < 30 ? ['/cart', '/more', '/thanks'] : ['/']);
        }
        // Not in the experiment.
        $this->trackVisit([], ['/cart', '/thanks']);

        $api = API::getInstance();
        $measure = $api->getExperimentResult(1, $id)['measures'][0];

        $this->assertSame(['Visits that complete the funnel: Checkout', 'primary', 'share'], [$measure['name'], $measure['role'], $measure['unit']]);
        $this->assertSame([10, 30], array_column($measure['variants'], 'nb_visits_with_outcome'));
        $this->assertSame([0.25, 0.75, 'better'], [$measure['variants'][0]['value'], $measure['variants'][1]['value'], $measure['variants'][1]['verdict']]);

        $this->assertSame([0, 30], array_column($api->getExperimentResult(1, $id, 'actions>=3')['measures'][0]['variants'], 'nb_visits_with_outcome'));
        $this->assertNotNull($api->getExperimentEvolution(1, $id));
    }

    public function testAFunnelThatIsDeletedLeavesTheExperimentWithoutOutcomes()
    {
        $this->needs('RebelFunnels');
        $idFunnel = $this->addFunnel();
        $id = $this->addExperiment(['type' => 'funnel', 'idfunnel' => $idFunnel]);
        $this->trackVisit([$id => 2], ['/cart', '/thanks']);

        FunnelsApi::getInstance()->deleteFunnel(1, $idFunnel);

        $this->assertSame([0, 0], array_column(API::getInstance()->getExperimentResult(1, $id)['measures'][0]['variants'], 'nb_visits_with_outcome'));
    }

    public function testAnExperimentIsJudgedByTheVisitsThatAnswerAFormAndShowsTheirAnswers()
    {
        $this->needs('UserFeedback');
        $idForm = $this->addForm(1, 'Page feedback');
        $other = $this->addForm(1, 'Other form');
        $id = $this->addExperiment(['type' => 'feedback', 'idform' => $idForm]);

        foreach ([[1, 'y'], [1, 'n'], [2, 'y'], [2, 'y'], [2, 'y'], [1, null], [2, null]] as [$variant, $answer]) {
            $this->trackVisit([$id => $variant], ['/']);
            if ($answer !== null) {
                $this->answer($idForm, $answer);
            }
        }
        $this->answer($other, 'n');
        $this->trackVisit([], ['/']);
        $this->answer($idForm, 'n');

        $result = API::getInstance()->getExperimentResult(1, $id);

        $this->assertSame('Visits that answer the form: Page feedback', $result['measures'][0]['name']);
        $this->assertSame([2, 3], array_column($result['measures'][0]['variants'], 'nb_visits_with_outcome'));
        $this->assertSame(
            [['name' => 'Visits that answer the form: Page feedback', 'answers' => [
                ['question' => 'Was the page clear?', 'answer' => 'No', 'counts' => [1 => 1]],
                ['question' => 'Was the page clear?', 'answer' => 'Yes', 'counts' => [1 => 1, 2 => 3]],
            ]]],
            $result['feedback']
        );
    }

    public function testTheFormOffersTheFunnelsAndFormsOfTheWebsite()
    {
        $this->needs('RebelFunnels', 'UserFeedback');
        Fixture::createWebsite('2024-01-01 00:00:00');
        $idFunnel = $this->addFunnel();
        $idForm = $this->addForm(1, 'Page feedback');
        $this->addForm(2, 'Form of another website');

        $choices = Measures::getChoices(1);

        $this->assertSame('Visits that complete the funnel: Checkout', $choices['funnel:' . $idFunnel]);
        $this->assertSame('Visits that answer the form: Page feedback', $choices['feedback:' . $idForm]);
        $this->assertNotContains('Visits that answer the form: Form of another website', $choices);
        $this->assertSame(['type' => 'funnel', 'idfunnel' => $idFunnel], Measures::normalize(1, Measures::fromChoice('funnel:' . $idFunnel)));
        $this->assertSame('feedback:' . $idForm, Measures::toChoice(['type' => 'feedback', 'idform' => $idForm]));
    }

    public function testFunnelsAndFormsAreNotMeasuresWithoutTheirPlugins()
    {
        $this->needs('RebelFunnels', 'UserFeedback');
        $idFunnel = $this->addFunnel();
        $idForm = $this->addForm(1, 'Page feedback');
        $id = $this->addExperiment(['type' => 'feedback', 'idform' => $idForm]);
        $this->trackVisit([$id => 2], ['/']);
        $this->answer($idForm, 'y');

        Manager::getInstance()->deactivatePlugin('RebelFunnels');
        Manager::getInstance()->deactivatePlugin('UserFeedback');

        $this->assertSame([], preg_grep('/^(funnel|feedback):/', array_keys(Measures::getChoices(1))));
        $result = API::getInstance()->getExperimentResult(1, $id);
        $this->assertSame([[0, 0], []], [array_column($result['measures'][0]['variants'], 'nb_visits_with_outcome'), $result['feedback'][0]['answers']]);

        $this->expectExceptionMessage('A measure is');
        $this->addExperiment(['type' => 'funnel', 'idfunnel' => $idFunnel], 'second');
    }

    /**
     * Skips the test where the other plugins are not there, as where this plugin is tested on
     * its own.
     */
    private function needs(string ...$plugins): void
    {
        foreach ($plugins as $plugin) {
            if (!Manager::getInstance()->isPluginActivated($plugin)) {
                $this->markTestSkipped('The plugin ' . $plugin . ' is not installed.');
            }
        }
    }

    private function addFunnel(): int
    {
        return FunnelsApi::getInstance()->addFunnel(1, 'Checkout', [
            ['name' => 'Cart', 'rules' => [['type' => 'url', 'operator' => 'contains', 'value' => '/cart']]],
            ['name' => 'Thanks', 'rules' => [['type' => 'url', 'operator' => 'contains', 'value' => '/thanks']]],
        ]);
    }

    private function addForm(int $idSite, string $name): int
    {
        Db::query(
            'INSERT INTO ' . Common::prefixTable('user_feedback_forms') . ' (name, content, site_id) VALUES (?, ?, ?)',
            [$name, json_encode(self::FORM), $idSite]
        );

        return (int) Db::get()->lastInsertId();
    }

    /**
     * Stores what the last tracked visit answers.
     */
    private function answer(int $idForm, string $clear): void
    {
        $result = self::FORM;
        $result[0]['userData'] = [$clear];
        $result[1]['userData'] = ['Text of a visitor'];
        $idVisit = (int) Db::fetchOne('SELECT MAX(idvisit) FROM ' . Common::prefixTable('log_visit'));

        Db::query(
            'INSERT INTO ' . Common::prefixTable('user_feedback_results') . " (form_id, container_id, tag_name, site_id, idvisit, result, page_url) VALUES (?, 'abc', 'tag', 1, ?, ?, '/')",
            [$idForm, $idVisit, json_encode($result)]
        );
    }

    /**
     * @param array<string, mixed> $measure
     */
    private function addExperiment(array $measure, string $name = 'judged'): int
    {
        return API::getInstance()->insertExperiment(1, $name, '', '', '2024-03-01', '2024-03-31', [['js' => '']], 100, 'any', '', $measure);
    }

    /**
     * @param array<int, int> $experiments the variant by experiment
     * @param string[] $paths
     */
    private function trackVisit(array $experiments, array $paths): void
    {
        $entries = [];
        foreach ($experiments as $idExperiment => $variant) {
            $entries[] = $idExperiment . '-' . $variant . '-0123456789abcdef0123456789abcdef';
        }

        $tracker = Fixture::getTracker(1, self::DAY . ' 10:00:00', true, true);
        $tracker->setTokenAuth(Fixture::getTokenAuth());
        $tracker->setNewVisitorId();
        // Matomo takes requests from one address for one visit.
        $tracker->setIp('10.0.0.' . (1 + $this->visits++));

        foreach ($paths as $minutes => $path) {
            if ($entries) {
                $tracker->setCustomTrackingParameter('rexp', implode(',', $entries));
            }
            $tracker->setForceVisitDateTime(date('Y-m-d H:i:s', strtotime(self::DAY . ' 10:00:00 UTC') + $minutes * 60));
            $tracker->setUrl('http://example.org' . $path);
            Fixture::checkResponse($tracker->doTrackPageView('Page'));
        }
    }
}
