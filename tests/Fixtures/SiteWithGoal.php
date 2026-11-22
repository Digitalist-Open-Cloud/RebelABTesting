<?php

namespace Piwik\Plugins\RebelABTesting\tests\Fixtures;

use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Tests\Framework\Fixture;

/**
 * A website with a goal for the page /thanks and a visit, without which Matomo shows how to start
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

        $tracker = self::getTracker(1, '2024-03-05 10:00:00', true, true);
        $tracker->setUrl('http://example.org/');
        self::checkResponse($tracker->doTrackPageView('Home'));
    }

    public function tearDown(): void
    {
    }
}
