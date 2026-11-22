<?php

namespace Piwik\Plugins\RebelABTesting\tests\Integration;

use Piwik\Common;
use Piwik\Date;
use Piwik\Db;
use Piwik\Mail;
use Piwik\Piwik;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\RebelABTesting\API;
use Piwik\Plugins\RebelABTesting\Dao\LogExperiment;
use Piwik\Plugins\RebelABTesting\Tasks;
use Piwik\Plugins\RebelABTesting\Widgets\GetExperiments;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * The result of an experiment in the list, and in the email to the one who added it.
 *
 * @group RebelABTesting
 * @group Plugins
 */
class NotificationsTest extends IntegrationTestCase
{
    /**
     * @var array<int, array{to: string[], subject: string, body: string}>
     */
    private $mails = [];

    /**
     * @var int
     */
    private $idExperiment;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::loadAllTranslations();
        Fixture::createSuperUser();
        Fixture::createWebsite('2024-01-01 00:00:00');
        FakeAccess::clearAccess(true);
        Date::$now = strtotime('2024-03-05 12:00:00 UTC');
        $idGoal = GoalsApi::getInstance()->addGoal(1, 'Thanks', 'url', 'thanks', 'contains');
        $this->idExperiment = $this->add('button', ['type' => 'goal', 'idgoal' => $idGoal]);

        $this->mails = [];
        Piwik::addAction('Mail.send', function (Mail $mail) {
            $this->mails[] = ['to' => array_keys($mail->getRecipients()), 'subject' => $mail->getSubject(), 'body' => $mail->getBodyText()];
        });
        $_GET = ['idSite' => '1'];
    }

    public function tearDown(): void
    {
        Date::$now = null;
        $_GET = [];

        parent::tearDown();
    }

    public function provideContainerConfig()
    {
        return ['Piwik\Access' => new FakeAccess(), 'Piwik\Mail\Transport' => new class extends Mail\Transport {
            public function send(Mail $mail)
            {
                return true;
            }
        }];
    }

    public function testTheListShowsWhatThePrimaryMeasureSays()
    {
        $this->addVisits($this->idExperiment, [1 => [40, 8], 2 => [40, 20]]);
        $this->addVisits($this->add('worse', ['type' => 'bounce']), [1 => [40, 0], 2 => [40, 0]], 30);
        $this->addVisits($this->add('too early', ['type' => 'bounce']), [1 => [3, 0], 2 => [3, 0]]);
        $this->add('no visits', ['type' => 'bounce']);
        $door = API::getInstance()->insertExperiment(1, 'door', '', '', '2024-03-01', '2024-03-31', [], 100, 'any', '', [], 'fake_door', ['selector' => '#menu', 'label' => 'Export', 'message' => 'Soon']);
        $this->addVisits($door, [2 => [8, 0]], 0, 2);

        $html = (new GetExperiments())->render();

        $this->assertStringContainsString('rebelABTestingVerdict-better">Green is better (+150%)</span>', $html);
        $this->assertStringContainsString('rebelABTestingVerdict-not_enough_data">Not enough data yet</span>', $html);
        $this->assertStringContainsString('rebelABTestingVerdict-no_difference">25% clicked</span>', $html);
        $this->assertStringContainsString('rebelABTestingVerdict-worse">The original is better</span>', $html);
        $this->assertSame(4, substr_count($html, 'rebelABTestingVerdict '));
    }

    public function testTheOneWhoAddedAnExperimentIsEmailedOnceWhenAVariantIsBetter()
    {
        $this->addVisits($this->idExperiment, [1 => [40, 8], 2 => [40, 20]]);

        (new Tasks())->notifyResults();
        (new Tasks())->notifyResults();

        $this->assertCount(1, $this->mails);
        $this->assertSame(['hello@example.org'], $this->mails[0]['to']);
        $this->assertSame('Experiment button has a result', $this->mails[0]['subject']);
        $this->assertStringContainsString('Result: Green is better (+150%)', $this->mails[0]['body']);
        $this->assertStringContainsString('category=RebelABTesting_RebelABTesting&subcategory=' . $this->idExperiment, $this->mails[0]['body']);
    }

    public function testNothingIsSentWithoutAResultOrWhenItIsNotAskedFor()
    {
        $this->addVisits($this->idExperiment, [1 => [40, 8], 2 => [40, 9]]);
        $quiet = API::getInstance()->insertExperiment(1, 'quiet', '', '', '2024-03-01', '2024-03-31', [['name' => 'Green']], 100, 'any', '', ['type' => 'bounce'], 'ab', [], [], 95, 'none', '', false);
        $this->addVisits($quiet, [1 => [40, 0], 2 => [40, 0]], 30);
        $this->add('planned', ['type' => 'bounce'], '2024-04-01', '2024-04-30');

        (new Tasks())->notifyResults();

        $this->assertSame([], $this->mails);
    }

    public function testTheEndOfAnExperimentIsToldOnceWithItsResult()
    {
        $this->addVisits($this->idExperiment, [1 => [40, 8], 2 => [40, 9]]);
        $this->add('nothing to judge by', []);
        Date::$now = strtotime('2024-04-01 06:00:00 UTC');

        (new Tasks())->notifyResults();
        (new Tasks())->notifyResults();

        $this->assertSame(['Experiment button has ended', 'Experiment nothing to judge by has ended'], array_column($this->mails, 'subject'));
        $this->assertStringContainsString('Result: No clear difference', $this->mails[0]['body']);
        $this->assertStringContainsString('Result: no result', $this->mails[1]['body']);
    }

    /**
     * @param array<string, mixed> $measure
     */
    private function add(string $name, array $measure, string $from = '2024-03-01', string $to = '2024-03-31'): int
    {
        return API::getInstance()->insertExperiment(1, $name, '', '', $from, $to, [['name' => 'Green']], 100, 'any', '', $measure);
    }

    /**
     * Visits as the tracker would have logged them.
     *
     * @param array<int, array{0: int, 1: int}> $perVariant the visits of each variant, and how many of them reached the goal
     * @param int $bounces how many visits of the variant are a single page view, when the rest have two
     */
    private function addVisits(int $idExperiment, array $perVariant, int $bounces = 0, int $clicks = 0): void
    {
        static $idVisit = 1000;

        foreach ($perVariant as $variant => [$visits, $converted]) {
            for ($visit = 0; $visit < $visits; $visit++) {
                $idVisit++;
                $actions = $variant > 1 && $visit < $bounces ? 1 : 2;
                Db::query(
                    'INSERT INTO ' . Common::prefixTable(LogExperiment::TABLE) . ' (idsite, idvisit, idvisitor, idexperiment, experiment_name, variant, assignment_id, server_time, clicked) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$idVisit, substr(md5((string) $idVisit), 0, 8), $idExperiment, 'x', $variant, md5((string) $idVisit), '2024-03-04 10:00:00', $visit < $clicks ? 1 : 0]
                );
                Db::query(
                    'INSERT INTO ' . Common::prefixTable('log_visit') . ' (idvisit, idsite, idvisitor, visit_first_action_time, visit_last_action_time, visit_total_actions, config_id, location_ip) VALUES (?, 1, ?, ?, ?, ?, ?, ?)',
                    [$idVisit, substr(md5((string) $idVisit), 0, 8), '2024-03-04 10:00:00', '2024-03-04 10:05:00', $actions, 'abcdefgh', 'abcd']
                );
                if ($visit < $converted) {
                    Db::query(
                        'INSERT INTO ' . Common::prefixTable('log_conversion') . ' (idvisit, idsite, idvisitor, server_time, idgoal, buster, url, revenue) VALUES (?, 1, ?, ?, 1, 0, ?, 0)',
                        [$idVisit, substr(md5((string) $idVisit), 0, 8), '2024-03-04 10:02:00', 'http://example.org/thanks']
                    );
                }
            }
        }
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);

        $fixture->extraPluginsToLoad = ['RebelABTesting'];
    }
}
