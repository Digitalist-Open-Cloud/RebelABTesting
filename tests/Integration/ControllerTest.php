<?php

namespace Piwik\Plugins\RebelABTesting\tests\Integration;

use Exception;
use Piwik\Date;
use Piwik\NoAccessException;
use Piwik\Nonce;
use Piwik\Piwik;
use Piwik\Plugin\WidgetsProvider;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\RebelABTesting\API;
use Piwik\Plugins\RebelABTesting\Controller;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Widgets\GetABTesting;
use Piwik\Plugins\RebelABTesting\Widgets\GetExperiments;
use Piwik\Container\StaticContainer;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Widget\WidgetConfig;

/**
 * @group RebelABTesting
 * @group Plugins
 */
class ControllerTest extends IntegrationTestCase
{
    /**
     * @var int
     */
    private $idExperiment;

    /**
     * @var int
     */
    private $idGoal;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::loadAllTranslations();
        Fixture::createWebsite('2024-01-01 00:00:00');
        Fixture::createWebsite('2024-01-01 00:00:00');
        FakeAccess::clearAccess(true);
        Date::$now = strtotime('2024-03-05 12:00:00 UTC');
        $this->idGoal = GoalsApi::getInstance()->addGoal(2, 'Signed <up>', 'url', 'thanks', 'contains');
        GoalsApi::getInstance()->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');
        $this->idExperiment = API::getInstance()->insertExperiment(1, 'button', 'A secret plan', 'Internal', '2024-03-01', '2024-03-31', [['name' => 'Green', 'changes' => [['selector' => 'h1', 'action' => 'text', 'value' => 'New <headline>']], 'redirect' => '', 'css' => 'h1{}', 'js' => "a('<b>')"]], 50, 'contains', '/pricing');
        $_GET = $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function tearDown(): void
    {
        Date::$now = null;
        $_GET = $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);

        parent::tearDown();
    }

    public function provideContainerConfig()
    {
        return ['Piwik\Access' => new FakeAccess()];
    }

    public function testTheTagGetsTheRunningExperimentsOfAWebsiteWithoutBeingLoggedIn()
    {
        $api = API::getInstance();
        $api->insertExperiment(1, 'planned', '', '', '2024-04-01', '2024-04-30', [['js' => 'later()']]);
        $api->insertExperiment(1, 'ended', '', '', '2024-02-01', '2024-02-29', [['js' => 'before()']]);
        $api->stopExperiment($api->insertExperiment(1, 'stopped', '', '', '2024-03-01', '2024-03-31', [['js' => 'stopped()']]), 1);
        $api->insertExperiment(2, 'other website', '', '', '2024-03-01', '2024-03-31', [['js' => 'other()']]);
        FakeAccess::clearAccess(false);
        FakeAccess::$identity = 'anonymous';

        $this->assertSame(
            ['experiments' => [[
                'id' => $this->idExperiment,
                'kind' => 'ab',
                'door' => null,
                'from_date' => '2024-03-01',
                'to_date' => '2024-03-31',
                'share' => 50,
                'match' => 'contains',
                'value' => '/pricing',
                'exclude_match' => 'none',
                'exclude_value' => '',
                'variants' => [['changes' => [['selector' => 'h1', 'action' => 'text', 'value' => 'New <headline>']], 'redirect' => '', 'css' => 'h1{}', 'js' => "a('<b>')"]],
            ]]],
            $this->getPublic(1)
        );
        $this->assertSame(['experiments' => []], $this->getPublic(3));
    }

    public function testTheTagGetsAFakeDoor()
    {
        $door = ['selector' => '#menu', 'position' => 'after', 'element' => 'button', 'label' => 'Export', 'css_class' => '', 'message' => 'Soon'];
        $id = API::getInstance()->insertExperiment(2, 'door', '', '', '2024-03-01', '2024-03-31', [], 100, 'any', '', [], 'fake_door', $door);

        $this->assertSame(
            ['id' => $id, 'kind' => 'fake_door', 'door' => $door, 'from_date' => '2024-03-01', 'to_date' => '2024-03-31', 'share' => 100, 'match' => 'any', 'value' => '', 'exclude_match' => 'none', 'exclude_value' => '', 'variants' => []],
            $this->getPublic(2)['experiments'][0]
        );
    }

    public function testAFakeDoorIsAddedFromTheFormAndHasAPage()
    {
        $door = ['selector' => '#menu', 'position' => 'prepend', 'element' => 'link', 'label' => 'Compare plans', 'css_class' => 'nav-link', 'message' => 'Coming soon'];
        $this->post(['idSite' => '2'], ['kind' => 'fake_door', 'door' => $door] + $this->getForm('Door'));
        $this->redirects('addExperiment');

        $experiment = (new Experiments())->getAllForSite(2)[0];
        $this->assertSame(['fake_door', $door, []], [$experiment['kind'], $experiment['door'], $experiment['variants']]);

        $_POST = [];
        $_GET = ['idSite' => '2', 'idExperiment' => (string) $experiment['id']];
        $html = (new Controller())->result();
        $this->assertStringContainsString('Fake door: Compare plans', $html);
        $this->assertStringContainsString('Visits that clicked it', $html);
        $this->assertStringContainsString('#rabt-preview=' . $experiment['id'] . '-2-' . $experiment['preview_token'], $html);

        $_GET = ['idSite' => '2', 'id' => (string) $experiment['id']];
        $edit = (new Controller())->editExperimentForm();
        $this->assertStringContainsString('value="fake_door" class="rebelABTestingKind" checked>', $edit);
        $this->assertStringContainsString('name="door[label]" id="door_label" value="Compare plans"', $edit);
        $this->assertStringContainsString('<option value="prepend" selected>', $edit);
    }

    public function testAWatchedElementIsAddedFromTheFormAndHasAPageWithoutPreview()
    {
        $this->post(['idSite' => '2'], ['kind' => 'watch', 'watch' => ['selector' => '.signup'], 'door' => ['selector' => '#left-over']] + $this->getForm('Watch'));
        $this->redirects('addExperiment');

        $experiment = (new Experiments())->getAllForSite(2)[0];
        $this->assertSame(['watch', ['selector' => '.signup']], [$experiment['kind'], $experiment['door']]);
        Date::$now = strtotime('2024-05-10 12:00:00 UTC');
        $this->assertSame(['selector' => '.signup'], $this->getPublic(2)['experiments'][0]['door']);

        $_POST = [];
        $_GET = ['idSite' => '2', 'idExperiment' => (string) $experiment['id']];
        $html = (new Controller())->result();
        $this->assertStringContainsString('Watch an element: .signup', $html);
        $this->assertStringNotContainsString('rabt-preview', $html);

        $_GET = ['idSite' => '2', 'id' => (string) $experiment['id']];
        $edit = (new Controller())->editExperimentForm();
        $this->assertStringContainsString('value="watch" class="rebelABTestingKind" checked>', $edit);
        $this->assertStringContainsString('name="watch[selector]" id="watch_selector" value=".signup"', $edit);
        $this->assertStringContainsString('name="door[selector]" id="door_selector" value=""', $edit);
    }

    public function testAPreviewLinkAlsoGetsAnExperimentThatIsNotRunning()
    {
        $api = API::getInstance();
        $planned = $api->getExperiment($api->insertExperiment(1, 'planned', '', '', '2024-04-01', '2024-04-30', [['js' => 'later()']]), 1);
        FakeAccess::clearAccess(false);

        $ids = function (string $preview): array {
            $_GET = ['idSite' => '1', 'preview' => $preview];

            return array_column(json_decode((new Controller())->getExperimentsPublic(), true)['experiments'], 'id');
        };

        $this->assertSame([$this->idExperiment, $planned['id']], $ids($planned['id'] . '-' . $planned['preview_token']));
        $this->assertSame([$this->idExperiment], $ids($planned['id'] . '-' . str_repeat('0', 32)));
        $this->assertSame([$this->idExperiment], $ids($planned['id'] . '-'));
        $this->assertSame([$this->idExperiment], $ids('99-' . $planned['preview_token']));
    }

    public function testAnExperimentIsAddedFromTheForm()
    {
        $this->post(['idSite' => '2'], $this->getForm('Form test'));

        try {
            (new Controller())->addExperiment();
            $this->fail('There was no redirect.');
        } catch (Exception $e) {
            $this->assertStringContainsString('subcategory=RebelABTesting_ManageExperiments', $e->getMessage());
        }

        $experiment = (new Experiments())->getAllForSite(2)[0];
        $this->assertSame(['Form test', 25, 'starts_with', 'https://example.org/shop'], [$experiment['name'], $experiment['traffic_share'], $experiment['target_match'], $experiment['target_value']]);
        $this->assertSame(['type' => 'goal', 'idgoal' => $this->idGoal], $experiment['primary_measure']);
        $this->assertSame(['contains', '/checkout', 99], [$experiment['exclude_match'], $experiment['exclude_value'], $experiment['confidence']]);
        $this->assertSame([['type' => 'event', 'category' => 'Video', 'action' => 'Play']], $experiment['guardrails']);
        $this->assertSame(
            [
                ['name' => 'One', 'changes' => [['selector' => '.buy', 'action' => 'add_class', 'value' => 'green']], 'redirect' => '', 'css' => '', 'js' => "// two\nlines('<b>')"],
                ['name' => '', 'changes' => [], 'redirect' => '', 'css' => 'b{}', 'js' => ''],
            ],
            $experiment['variants']
        );
    }

    public function testChangesFromTheFormsGoThroughMatomosApiSoThatAnAuditLogSeesThem()
    {
        $seen = [];
        foreach (['insertExperiment', 'updateExperiment', 'stopExperiment', 'deleteExperiment'] as $method) {
            Piwik::addAction('API.RebelABTesting.' . $method . '.end', function () use (&$seen, $method) {
                $seen[] = $method;
            });
        }

        $this->post(['idSite' => '2'], $this->getForm('Audited'));
        $this->redirects('addExperiment');
        $id = (string) (new Experiments())->getAllForSite(2)[0]['id'];
        $this->post(['idSite' => '2'], ['id' => $id] + $this->getForm('Audited again'));
        $this->redirects('updateExperiment');
        $this->post(['idSite' => '2'], ['id' => $id, 'nonce' => Nonce::getNonce(Controller::NONCE)]);
        $this->redirects('stop');
        $this->post(['idSite' => '2'], ['id' => $id, 'nonce' => Nonce::getNonce(Controller::NONCE)]);
        $this->redirects('delete');

        $this->assertSame(['insertExperiment', 'updateExperiment', 'stopExperiment', 'deleteExperiment'], $seen);
    }

    public function testAnExperimentIsUpdatedStoppedAndDeletedFromTheForm()
    {
        $api = API::getInstance();
        $this->post(['idSite' => '1'], ['id' => (string) $this->idExperiment] + $this->getForm('renamed'));
        $this->redirects('updateExperiment');
        $this->assertSame('renamed', $api->getExperiment($this->idExperiment, 1)['name']);

        $this->post(['idSite' => '1'], ['id' => (string) $this->idExperiment, 'nonce' => Nonce::getNonce(Controller::NONCE)]);
        $this->redirects('stop');
        $this->assertSame('ended', $api->getExperiment($this->idExperiment, 1)['status']);

        $this->post(['idSite' => '1'], ['id' => (string) $this->idExperiment, 'nonce' => Nonce::getNonce(Controller::NONCE)]);
        $this->redirects('delete');
        $this->assertNull($api->getExperiment($this->idExperiment, 1));
    }

    /**
     * @dataProvider getActions
     */
    public function testAChangeNeedsTheTokenOfTheForm(string $action)
    {
        $this->post(['idSite' => '1'], ['id' => (string) $this->idExperiment, 'nonce' => 'made-up'] + $this->getForm('renamed'));

        $this->refuses($action, 'security check');
    }

    /**
     * @dataProvider getActions
     */
    public function testAChangeIsNotMadeByALink(string $action)
    {
        $_GET = ['idSite' => '1', 'id' => (string) $this->idExperiment, 'nonce' => Nonce::getNonce(Controller::NONCE)] + $this->getForm('renamed');

        $this->refuses($action, 'security check');
    }

    /**
     * @dataProvider getActions
     */
    public function testAChangeNeedsWriteAccessToTheWebsite(string $action)
    {
        $this->post(['idSite' => '1'], ['id' => (string) $this->idExperiment] + $this->getForm('renamed'));
        FakeAccess::clearAccess(false, [2], [1]);

        $this->refuses($action, 'checkUserHasWriteAccess');
    }

    public function getActions(): array
    {
        return [['addExperiment'], ['updateExperiment'], ['stop'], ['delete']];
    }

    public function testTheEditFormNeedsWriteAccessToTheWebsite()
    {
        $_GET = ['idSite' => '1', 'id' => (string) $this->idExperiment];
        FakeAccess::clearAccess(false, [2], [1]);

        $this->expectException(NoAccessException::class);

        (new Controller())->editExperimentForm();
    }

    /**
     * @dataProvider getAdminWidgets
     */
    public function testCreatingExperimentsIsForUsersWhoCanWriteToTheWebsite(string $widget)
    {
        $_GET = ['idSite' => '1'];
        FakeAccess::clearAccess(false, [2], [1]);

        $config = new WidgetConfig();
        $widget::configure($config);
        $this->assertFalse($config->isEnabled());

        $this->expectException(NoAccessException::class);

        (new $widget())->render();
    }

    public function getAdminWidgets(): array
    {
        return [[GetABTesting::class]];
    }

    public function testTheListIsForUsersOfTheWebsite()
    {
        $_GET = ['idSite' => '1'];
        FakeAccess::clearAccess(false, [2], []);

        $this->expectException(NoAccessException::class);

        (new GetExperiments())->render();
    }

    public function testAnEmptyListTellsHowToPutTheTagOnTheWebsite()
    {
        $_GET = ['idSite' => '2'];

        $this->assertStringContainsString('Add the tag &quot;Rebel A/B Testing&quot; once', (new GetExperiments())->render());

        FakeAccess::clearAccess(false, [], [2]);
        $this->assertStringNotContainsString('Add the tag', (new GetExperiments())->render());
    }

    public function testAViewerSeesTheListWithoutTheWaysToChangeIt()
    {
        API::getInstance()->insertExperiment(2, 'other-site', '', '', '2024-03-01', '2024-03-31', [['js' => '']]);
        $_GET = ['idSite' => '1'];
        FakeAccess::clearAccess(false, [], [1]);

        $html = (new GetExperiments())->render();

        $this->assertStringContainsString('category=RebelABTesting_RebelABTesting&amp;subcategory=' . $this->idExperiment . '">button</a>', $html);
        $this->assertStringContainsString('Running', $html);
        $this->assertStringNotContainsString('other-site', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('name="nonce"', $html);
    }

    public function testAnAdminCanStopARunningExperimentFromTheList()
    {
        $_GET = ['idSite' => '1'];
        FakeAccess::clearAccess(false, [1]);

        $html = (new GetExperiments())->render();

        $this->assertStringContainsString('action=stop&amp;idSite=1', $html);
        $this->assertStringContainsString('action=delete&amp;idSite=1', $html);
        $this->assertStringContainsString('name="nonce" value="' . Nonce::getNonce(Controller::NONCE) . '"', $html);
    }

    public function testTheFormsCarryTheTokenAndTheExperiment()
    {
        $_GET = ['idSite' => '1', 'id' => (string) $this->idExperiment, 'period' => 'week', 'date' => '2024-03-05'];
        $nonce = 'name="nonce" value="' . Nonce::getNonce(Controller::NONCE) . '"';

        $create = (new GetABTesting())->render();
        $this->assertStringContainsString($nonce, $create);
        $this->assertStringContainsString('action="?module=RebelABTesting&amp;action=addExperiment&amp;idSite=1&amp;period=week&amp;date=2024-03-05"', $create);
        $this->assertStringContainsString('name="from_date" id="from_date" value="2024-03-05"', $create);
        $this->assertStringContainsString('name="variants[0][css]"', $create);
        $this->assertStringContainsString('name="variants[0][redirect]" value=""', $create);
        $this->assertStringContainsString('<option value="banner" data-changes="[{&quot;selector&quot;:&quot;body&quot;,&quot;action&quot;:&quot;insert_first&quot;,&quot;value&quot;:&quot;Free shipping this week&quot;}', $create);
        $this->assertStringContainsString('value="ab" class="rebelABTestingKind" checked>', $create);
        $this->assertStringContainsString('data-result="Run for at least {days} days: {visits} visits in each of the {groups} groups.', $create);
        $this->assertStringContainsString('name="door[selector]"', $create);
        $this->assertSame(3, substr_count($create, '<option value="" selected>None</option>'));
        $this->assertStringContainsString('<option value="goal:1">Goal: Thanks</option>', $create);
        $this->assertStringContainsString('<option value="bounce">Bounces</option>', $create);
        $this->assertStringContainsString('<option value="goal_revenue:1">Revenue per visit of goal: Thanks</option>', $create);
        $this->assertStringContainsString('<option value="visit_length">Visit length</option>', $create);
        $this->assertStringNotContainsString('<option value="revenue">', $create);
        $this->assertStringContainsString('<option value="95" selected>95%</option>', $create);
        $this->assertStringContainsString('<option value="none" selected>None</option>', $create);
        $this->assertStringNotContainsString('Signed', $create);

        API::getInstance()->updateExperiment($this->idExperiment, 1, 'button', 'A secret plan', '', '2024-03-01', '2024-03-31', [['name' => 'Green', 'changes' => [['selector' => 'h1', 'action' => 'text', 'value' => 'New <headline>']], 'redirect' => '', 'css' => 'h1{}', 'js' => "a('<b>')"]], 50, 'contains', '/pricing', ['type' => 'search'], 'ab', [], [['type' => 'event', 'category' => 'Video "HD"', 'action' => '']]);
        $edit = (new Controller())->editExperimentForm();
        $this->assertStringContainsString('<option value="search" selected>', $edit);
        $this->assertStringContainsString('<option value="event" selected>', $edit);
        $this->assertStringContainsString('name="guardrail_0_category" id="guardrail_0_category" value="Video &quot;HD&quot;"', $edit);
        $this->assertStringContainsString($nonce, $edit);
        $this->assertStringContainsString('action=updateExperiment&amp;idSite=1', $edit);
        $this->assertStringContainsString('value="A secret plan"', $edit);
        $this->assertStringContainsString('<option value="contains" selected>', $edit);
        $this->assertStringContainsString('name="variants[0][name]" value="Green"', $edit);
        $this->assertStringContainsString('name="variants[0][changes][0][value]" value="New &lt;headline&gt;"', $edit);
        $this->assertStringContainsString('<option value="text" data-placeholder="The new text" selected>', $edit);
        $this->assertStringContainsString('a(&#039;&lt;b&gt;&#039;)</textarea>', $edit);
    }

    public function testAUserWithWriteAccessGetsTheFormWithoutCssAndJavaScript()
    {
        $id = API::getInstance()->insertExperiment(1, 'no code', '', '', '2024-03-01', '2024-03-31', [['changes' => [['selector' => '.ad', 'action' => 'hide']]]]);
        FakeAccess::clearAccess(false, [], [], 'writer', [1]);

        $_GET = ['idSite' => '1'];
        $create = (new GetABTesting())->render();
        $this->assertStringContainsString('name="variants[0][changes][0][selector]"', $create);
        $this->assertStringNotContainsString('[css]', $create);
        $this->assertStringNotContainsString('[js]', $create);

        $list = (new GetExperiments())->render();
        $this->assertSame(1, substr_count($list, 'action=editExperimentForm'));
        $this->assertStringContainsString('action=editExperimentForm&amp;idSite=1&amp;period=day&amp;date=yesterday&amp;id=' . $id . '"', $list);
        $this->assertSame(2, substr_count($list, 'action=delete'));

        $_GET = ['idSite' => '1', 'id' => (string) $id];
        $edit = (new Controller())->editExperimentForm();
        $this->assertStringContainsString('<option value="hide" data-placeholder="" selected>', $edit);
        $this->assertStringNotContainsString('[js]', $edit);
    }

    public function testEachExperimentHasAPageInTheMenuOfItsWebsite()
    {
        $_GET = ['idSite' => '1'];
        FakeAccess::clearAccess(false, [], [1]);

        $pages = [];
        foreach (StaticContainer::get(WidgetsProvider::class)->getWidgetConfigs() as $config) {
            if ($config->getModule() === 'RebelABTesting' && $config->getAction() === 'result') {
                $pages[] = [$config->getName(), $config->getParameters()['idExperiment']];
            }
        }

        $this->assertSame([['button', $this->idExperiment]], $pages);
    }

    public function testThePageOfAnExperimentShowsItsResultToAViewer()
    {
        $_GET = ['idSite' => '1', 'idExperiment' => (string) $this->idExperiment];
        FakeAccess::clearAccess(false, [], [1]);

        $html = (new Controller())->result();

        $this->assertStringContainsString('<h2>button</h2>', $html);
        $this->assertStringContainsString('Original', $html);
        $this->assertStringContainsString('Green', $html);
        $this->assertStringNotContainsString('A secret plan', $html);
        $this->assertStringNotContainsString('rabt-preview', $html);
    }

    public function testAnAdminGetsAPreviewLinkForEachVariant()
    {
        $experiment = API::getInstance()->getExperiment($this->idExperiment, 1);
        $_GET = ['idSite' => '1', 'idExperiment' => (string) $this->idExperiment];
        FakeAccess::clearAccess(false, [1]);

        $html = (new Controller())->result();

        $this->assertStringContainsString('#rabt-preview=' . $this->idExperiment . '-1-' . $experiment['preview_token'] . '"', $html);
        $this->assertStringContainsString('#rabt-preview=' . $this->idExperiment . '-2-' . $experiment['preview_token'] . '"', $html);
    }

    public function testThePageOfAnExperimentShowsItsDescriptionToThoseWhoCanChangeIt()
    {
        $_GET = ['idSite' => '1', 'idExperiment' => (string) $this->idExperiment];
        FakeAccess::clearAccess(false, [], [], 'writer', [1]);

        $html = (new Controller())->result();

        $this->assertStringContainsString('A secret plan', $html);
        $this->assertStringContainsString('Internal', $html);
    }

    public function testThePageOfAnExperimentNeedsAccessToItsWebsite()
    {
        $_GET = ['idSite' => '1', 'idExperiment' => (string) $this->idExperiment];
        FakeAccess::clearAccess(false, [], [2]);

        $this->expectException(NoAccessException::class);

        (new Controller())->result();
    }

    /**
     * @return array<string, mixed>
     */
    private function getPublic(int $idSite): array
    {
        $_GET = ['idSite' => (string) $idSite];

        return json_decode((new Controller())->getExperimentsPublic(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function getForm(string $name): array
    {
        return [
            'nonce' => Nonce::getNonce(Controller::NONCE),
            'name' => $name,
            'hypothesis' => 'h',
            'description' => 'd',
            'from_date' => '2024-05-01',
            'to_date' => '2024-05-31',
            'traffic_share' => '25',
            'target_match' => 'starts_with',
            'target_value' => 'https://example.org/shop',
            'exclude_match' => 'contains',
            'exclude_value' => '/checkout',
            'confidence' => '99',
            'primary_measure' => 'goal:' . $this->idGoal,
            'primary_measure_category' => 'left over from another choice',
            'guardrail_0' => '',
            'guardrail_1' => 'event',
            'guardrail_1_category' => 'Video',
            'guardrail_1_action' => 'Play',
            'variants' => [
                ['name' => 'One', 'css' => '', 'js' => "// two\nlines('<b>')", 'changes' => [['selector' => '.buy', 'action' => 'add_class', 'value' => 'green'], ['selector' => '', 'action' => 'text', 'value' => '']]],
                ['name' => '', 'css' => 'b{}', 'js' => ''],
            ],
        ];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $form
     */
    private function post(array $query, array $form): void
    {
        $_GET = $query;
        $_POST = $form;
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    private function redirects(string $action): void
    {
        try {
            (new Controller())->$action();
            $this->fail('There was no redirect.');
        } catch (Exception $e) {
            $this->assertStringContainsString('redirect you to this URL', $e->getMessage());
        }
    }

    private function refuses(string $action, string $message): void
    {
        try {
            (new Controller())->$action();
            $this->fail('The request was not refused.');
        } catch (Exception $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        FakeAccess::clearAccess(true);
        $experiment = API::getInstance()->getExperiment($this->idExperiment, 1);
        $this->assertSame(['button', 'running'], [$experiment['name'], $experiment['status']]);
        $this->assertCount(1, (new Experiments())->getAllForSite(1));
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);

        $fixture->extraPluginsToLoad = ['RebelABTesting'];
    }
}
