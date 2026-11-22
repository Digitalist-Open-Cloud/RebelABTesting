<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelExperiments;

use Piwik\Date;
use Piwik\Mail;
use Piwik\Piwik;
use Piwik\Plugins\RebelExperiments\Dao\Experiments;
use Piwik\Plugins\UsersManager\Model as UsersModel;
use Piwik\SettingsPiwik;

class Tasks extends \Piwik\Plugin\Tasks
{
    public function schedule()
    {
        $this->daily('notifyResults', null, self::LOW_PRIORITY);
    }

    /**
     * Emails the one who added an experiment when a variant is found better or worse on the primary
     * measure, and when the experiment has ended. Each of these is told once.
     */
    public function notifyResults(): void
    {
        $experiments = new Experiments();
        $results = new Results();
        $users = new UsersModel();

        foreach ($experiments->getAllToNotify() as $experiment) {
            $summary = $results->getSummary($experiment);
            $hasEnded = $experiment['status'] === Experiments::STATUS_ENDED;
            $isDecided = $summary !== null && in_array($summary['verdict'], [Results::VERDICT_BETTER, Results::VERDICT_WORSE], true);

            $state = $hasEnded ? Experiments::STATUS_ENDED : ($isDecided ? md5($summary['text']) : '');
            if ($state === '' || $state === $experiment['notified']) {
                continue;
            }

            $user = $users->getUser($experiment['created_by']);
            if (!empty($user['email'])) {
                $this->send($user['email'], $experiment, $summary, $hasEnded);
            }
            $experiments->setNotified($experiment['id'], $state);
        }
    }

    /**
     * @param array<string, mixed> $experiment
     * @param array{verdict: string, text: string}|null $summary
     */
    private function send(string $address, array $experiment, ?array $summary, bool $hasEnded): void
    {
        $result = $summary['text'] ?? Piwik::translate('RebelExperiments_MailNoResult');
        $page = ['idSite' => $experiment['idsite'], 'period' => 'day', 'date' => Date::now()->toString()];
        $link = SettingsPiwik::getPiwikUrl() . 'index.php?' . http_build_query(['module' => 'CoreHome', 'action' => 'index'] + $page)
            . '#?' . http_build_query($page + ['category' => 'RebelExperiments_RebelExperiments', 'subcategory' => $experiment['id']]);

        $mail = new Mail();
        $mail->setDefaultFromPiwik();
        $mail->addTo($address);
        $mail->setSubject(Piwik::translate($hasEnded ? 'RebelExperiments_MailSubjectEnded' : 'RebelExperiments_MailSubjectResult', $experiment['name']));
        $mail->setBodyText(implode("\n\n", [
            Piwik::translate($hasEnded ? 'RebelExperiments_MailEnded' : 'RebelExperiments_MailResult', $experiment['name']),
            Piwik::translate('RebelExperiments_MailResultLine', $result),
            $link,
        ]));
        $mail->safeSend();
    }
}
