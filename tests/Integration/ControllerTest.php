<?php

namespace Piwik\Plugins\RebelABTesting\tests\Integration;

use Exception;
use Piwik\Date;
use Piwik\NoAccessException;
use Piwik\Nonce;
use Piwik\Plugins\RebelABTesting\API;
use Piwik\Plugins\RebelABTesting\Controller;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Widgets\GetABTesting;
use Piwik\Plugins\RebelABTesting\Widgets\GetExperiments;
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

    public function setUp(): void
    {
        parent::setUp();

        Fixture::loadAllTranslations();
        Fixture::createWebsite('2024-01-01 00:00:00');
        Fixture::createWebsite('2024-01-01 00:00:00');
        FakeAccess::clearAccess(true);
        $this->idExperiment = API::getInstance()->insertExperiment(1, 'button', 'A secret plan', 'Internal', '2024-03-01', '2024-03-31', 'h1{}', "a('<b>')");
        Date::$now = strtotime('2024-03-05 12:00:00 UTC');
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

    public function testTheTagGetsARunningExperimentWithoutBeingLoggedIn()
    {
        FakeAccess::clearAccess(false);
        FakeAccess::$identity = 'anonymous';

        $this->assertSame(
            ['found' => true, 'name' => 'button', 'from_date' => '2024-03-01', 'to_date' => '2024-03-31', 'css_insert' => 'h1{}', 'js_insert' => "a('<b>')"],
            $this->getPublic($this->idExperiment, 1)
        );
    }

    /**
     * @dataProvider getTimesOutsideTheExperiment
     */
    public function testTheTagGetsNothingOutsideThePeriodOfTheExperiment(string $now)
    {
        Date::$now = strtotime($now . ' UTC');

        $this->assertSame(['found' => false], $this->getPublic($this->idExperiment, 1));
    }

    public function getTimesOutsideTheExperiment(): array
    {
        return [
            'the day before' => ['2024-02-29 23:59:59'],
            'the day after' => ['2024-04-01 00:00:00'],
        ];
    }

    public function testTheTagGetsNothingForAnotherWebsiteOrAnUnknownExperiment()
    {
        $this->assertSame(['found' => false], $this->getPublic($this->idExperiment, 2));
        $this->assertSame(['found' => false], $this->getPublic(99, 1));
    }

    public function testAnExperimentIsAddedFromTheForm()
    {
        $this->post(['idSite' => '2'], $this->getForm('Form test!'));

        try {
            (new Controller())->addExperiment();
            $this->fail('There was no redirect.');
        } catch (Exception $e) {
            $this->assertStringContainsString('subcategory=RebelABTesting_ExistingExperiments', $e->getMessage());
        }

        $experiment = (new Experiments())->getAllForSite(2)[0];
        $this->assertSame('Formtest', $experiment['name']);
        $this->assertSame("// two\nlines('<b>')", $experiment['js_insert']);
    }

    public function testAnExperimentIsUpdatedAndDeletedFromTheForm()
    {
        $this->post(['idSite' => '1'], ['id' => (string) $this->idExperiment] + $this->getForm('renamed'));
        $this->redirects('updateExperiment');
        $this->assertSame('renamed', API::getInstance()->getExperiment($this->idExperiment, 1)['name']);

        $this->post(['idSite' => '1'], ['id' => (string) $this->idExperiment, 'nonce' => Nonce::getNonce(Controller::NONCE)]);
        $this->redirects('delete');
        $this->assertNull(API::getInstance()->getExperiment($this->idExperiment, 1));
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
        return [['addExperiment'], ['updateExperiment'], ['delete']];
    }

    public function testTheEditFormNeedsAdminAccessToTheWebsite()
    {
        $_GET = ['idSite' => '1', 'id' => (string) $this->idExperiment];
        FakeAccess::clearAccess(false, [2], [1]);

        $this->expectException(NoAccessException::class);

        (new Controller())->editExperimentForm();
    }

    /**
     * @dataProvider getWidgets
     */
    public function testTheWidgetsAreForAdminsOfTheWebsite(string $widget)
    {
        $_GET = ['idSite' => '1'];
        FakeAccess::clearAccess(false, [2], [1]);

        $config = new WidgetConfig();
        $widget::configure($config);
        $this->assertFalse($config->isEnabled());

        $this->expectException(NoAccessException::class);

        (new $widget())->render();
    }

    public function getWidgets(): array
    {
        return [[GetExperiments::class], [GetABTesting::class]];
    }

    public function testTheListShowsTheExperimentsOfTheWebsiteToItsAdmin()
    {
        API::getInstance()->insertExperiment(2, 'other-site', '', '', '2024-03-01', '2024-03-31', '', '');
        $_GET = ['idSite' => '1'];
        FakeAccess::clearAccess(false, [1]);

        $html = (new GetExperiments())->render();

        $this->assertStringContainsString('button', $html);
        $this->assertStringContainsString('a(&#039;&lt;b&gt;&#039;)', $html);
        $this->assertStringNotContainsString('other-site', $html);
    }

    public function testTheFormsCarryTheTokenAndPostToTheWebsite()
    {
        $_GET = ['idSite' => '1', 'id' => (string) $this->idExperiment, 'period' => 'week', 'date' => '2024-03-05'];
        $nonce = 'name="nonce" value="' . Nonce::getNonce(Controller::NONCE) . '"';

        $create = (new GetABTesting())->render();
        $this->assertStringContainsString($nonce, $create);
        $this->assertStringContainsString('action="?module=RebelABTesting&amp;action=addExperiment&amp;idSite=1&amp;period=week&amp;date=2024-03-05"', $create);
        $this->assertStringContainsString('name="from_date" id="from_date" value="2024-03-05"', $create);

        $edit = (new Controller())->editExperimentForm();
        $this->assertStringContainsString($nonce, $edit);
        $this->assertStringContainsString('action=updateExperiment&amp;idSite=1', $edit);
        $this->assertStringContainsString('value="A secret plan"', $edit);
    }

    /**
     * @return array<string, mixed>
     */
    private function getPublic(int $id, int $idSite): array
    {
        $_GET = ['id' => (string) $id, 'idSite' => (string) $idSite];

        return json_decode((new Controller())->getExperimentPublic(), true);
    }

    /**
     * @return array<string, string>
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
            'css_insert' => '',
            'js_insert' => "// two\nlines('<b>')",
        ];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $form
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
        $this->assertSame('button', API::getInstance()->getExperiment($this->idExperiment, 1)['name']);
        $this->assertCount(1, (new Experiments())->getAllForSite(1));
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);

        $fixture->extraPluginsToLoad = ['RebelABTesting'];
    }
}
