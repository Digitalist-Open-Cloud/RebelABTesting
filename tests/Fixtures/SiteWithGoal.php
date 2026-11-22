<?php

namespace Piwik\Plugins\RebelExperiments\tests\Fixtures;

use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Tests\Framework\Fixture;

/**
 * A website with a goal for the page /thanks, which a visit can reach more than once, and a visit, without which Matomo shows how to start
 * tracking and no reports.
 */
class SiteWithGoal extends Fixture
{
    public function setUp(): void
    {
        if (!self::siteCreated(1)) {
            self::createWebsite('2024-01-01 00:00:00');
            GoalsApi::getInstance()->addGoal(1, 'Thanks page', 'url', '/thanks', 'contains');
        }
        // The browser of the UI tests is one visitor to Matomo, and has to be able to reach the goal
        // in each test.
        GoalsApi::getInstance()->updateGoal(1, 1, 'Thanks page', 'url', '/thanks', 'contains', false, false, true);

        $tracker = self::getTracker(1, '2024-03-05 10:00:00', true, true);
        $tracker->setUrl('http://example.org/');
        self::checkResponse($tracker->doTrackPageView('Home'));
    }

    public function tearDown(): void
    {
    }
}
