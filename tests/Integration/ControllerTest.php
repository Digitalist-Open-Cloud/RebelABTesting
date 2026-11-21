<?php

namespace Piwik\Plugins\RebelABTesting\tests\Integration;

use Exception;
use Piwik\Date;
use Piwik\NoAccessException;
use Piwik\Nonce;
use Piwik\Plugin\WidgetsProvider;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\RebelABTesting\API;
use Piwik\Plugins\RebelABTesting\Controller;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Widgets\GetABTesting;
use Piwik\Plugins\RebelABTesting\Widgets\GetExperiments;
use Piwik\Plugins\RebelABTesting\Widgets\GetInsertExperiment;
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
        $this->idExperiment = API::getInstance()->insertExperiment(1, 'button', 'A secret plan', 'Internal', '2024-03-01', '2024-03-31', [['name' => 'Green', 'css' => 'h1{}', 'js' => "a('<b>')"]], 50, 'contains', '/pricing');
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
                'from_date' => '2024-03-01',
                'to_date' => '2024-03-31',
                'share' => 50,
                'match' => 'contains',
                'value' => '/pricing',
                'variants' => [['css' => 'h1{}', 'js' => "a('<b>')"]],
            ]]],
            $this->getPublic(1)
        );
        $this->assertSame(['experiments' => []], $this->getPublic(3));
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
            $this->assertStringContainsString('subcategory=RebelABTesting_ExistingExperiments', $e->getMessage());
        }

        $experiment = (new Experiments())->getAllForSite(2)[0];
        $this->assertSame(['Form test', 25, 'starts_with', 'https://example.org/shop', $this->idGoal], [$experiment['name'], $experiment['traffic_share'], $experiment['target_match'], $experiment['target_value'], $experiment['primary_goal']]);
        $this->assertSame(
            [['name' => 'One', 'css' => '', 'js' => "// two\nlines('<b>')"], ['name' => '', 'css' => 'b{}', 'js' => '']],
            $experiment['variants']
        );
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
    public function testAChangeNeedsAdminAccessToTheWebsite(string $action)
    {
        $this->post(['idSite' => '1'], ['id' => (string) $this->idExperiment] + $this->getForm('renamed'));
        FakeAccess::clearAccess(false, [2], [1]);

        $this->refuses($action, 'checkUserHasAdminAccess');
    }

    public function getActions(): array
    {
        return [['addExperiment'], ['updateExperiment'], ['stop'], ['delete']];
    }

    public function testTheEditFormNeedsAdminAccessToTheWebsite()
    {
        $_GET = ['idSite' => '1', 'id' => (string) $this->idExperiment];
        FakeAccess::clearAccess(false, [2], [1]);

        $this->expectException(NoAccessException::class);

        (new Controller())->editExperimentForm();
    }

    /**
     * @dataProvider getAdminWidgets
     */
    public function testCreatingExperimentsIsForAdminsOfTheWebsite(string $widget)
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
        return [[GetABTesting::class], [GetInsertExperiment::class]];
    }

    public function testTheListIsForUsersOfTheWebsite()
    {
        $_GET = ['idSite' => '1'];
        FakeAccess::clearAccess(false, [2], []);

        $this->expectException(NoAccessException::class);

        (new GetExperiments())->render();
    }

    public function testAViewerSeesTheListWithoutTheWaysToChangeIt()
    {
        API::getInstance()->insertExperiment(2, 'other-site', '', '', '2024-03-01', '2024-03-31', [['js' => '']]);
        $_GET = ['idSite' => '1'];
        FakeAccess::clearAccess(false, [], [1]);

        $html = (new GetExperiments())->render();

        $this->assertStringContainsString('button', $html);
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
        $this->assertStringContainsString('<option value="" selected>None</option>', $create);
        $this->assertStringContainsString('>Thanks</option>', $create);
        $this->assertStringNotContainsString('Signed', $create);

        $edit = (new Controller())->editExperimentForm();
        $this->assertStringContainsString($nonce, $edit);
        $this->assertStringContainsString('action=updateExperiment&amp;idSite=1', $edit);
        $this->assertStringContainsString('value="A secret plan"', $edit);
        $this->assertStringContainsString('<option value="contains" selected>', $edit);
        $this->assertStringContainsString('name="variants[0][name]" value="Green"', $edit);
        $this->assertStringContainsString('a(&#039;&lt;b&gt;&#039;)</textarea>', $edit);
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
            'primary_goal' => (string) $this->idGoal,
            'variants' => [
                ['name' => 'One', 'css' => '', 'js' => "// two\nlines('<b>')"],
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
