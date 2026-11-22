<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelExperiments;

use Exception;
use Piwik\API\Request as ApiRequest;
use Piwik\Common;
use Piwik\Date;
use Piwik\Nonce;
use Piwik\Notification;
use Piwik\Piwik;
use Piwik\Plugins\RebelExperiments\Dao\Experiments;
use Piwik\Plugins\RebelExperiments\Validation\ExperimentValidator;
use Piwik\Request;
use Piwik\Site;
use Piwik\Url;

class Controller extends \Piwik\Plugin\Controller
{
    public const NONCE = 'RebelExperiments.experiment';

    private const LIST_PAGE = 'RebelExperiments_ManageExperiments';
    private const CREATE_PAGE = 'RebelExperiments_CreateNewExperiment';

    /**
     * @return int the requested website when the user may write to it, otherwise 0
     */
    public static function getWriteSite(): int
    {
        $idSite = Request::fromRequest()->getIntegerParameter('idSite', 0);

        return $idSite > 0 && Piwik::isUserHasWriteAccess($idSite) ? $idSite : 0;
    }

    public static function checkWriteSite(): int
    {
        $idSite = Request::fromRequest()->getIntegerParameter('idSite', 0);
        Piwik::checkUserHasWriteAccess($idSite);

        return $idSite;
    }

    public static function getActionUrl(string $action, int $idSite): string
    {
        return '?' . http_build_query(['module' => 'RebelExperiments', 'action' => $action] + self::getPageParameters($idSite));
    }

    /**
     * @return array<string, mixed> what the form of an experiment needs besides the experiment
     */
    public static function getFormVariables(int $idSite): array
    {
        return [
            'measures' => Measures::getChoices($idSite),
            'guardrailSlots' => range(0, Measures::MAX_GUARDRAILS - 1),
            'actions' => ExperimentValidator::ACTIONS,
            'templates' => Templates::getAll(),
            'canUseCode' => Piwik::isUserHasAdminAccess($idSite),
            'nonce' => Nonce::getNonce(self::NONCE),
            'matches' => ExperimentValidator::MATCHES,
            'maxVariants' => ExperimentValidator::MAX_VARIANTS,
            'kinds' => ExperimentValidator::KINDS,
            'excludes' => ExperimentValidator::EXCLUDES,
            'confidences' => ExperimentValidator::CONFIDENCES,
            'audienceVisitors' => Audience::VISITORS,
            'audienceDevices' => Audience::DEVICES,
            'doorPositions' => ExperimentValidator::DOOR_POSITIONS,
            'doorElements' => ExperimentValidator::DOOR_ELEMENTS,
        ];
    }

    public function addExperiment()
    {
        $idSite = $this->checkWriteRequest();

        try {
            ApiRequest::processRequest('RebelExperiments.insertExperiment', ['idSite' => $idSite] + $this->getExperimentFields(), []);
            $this->notify('RebelExperiments_ExperimentCreated', Notification::CONTEXT_SUCCESS);
        } catch (Exception $e) {
            $this->notify($e->getMessage(), Notification::CONTEXT_ERROR);
            $this->redirect(self::getReportingUrl($idSite, self::CREATE_PAGE));
            return;
        }

        $this->redirect(self::getReportingUrl($idSite, self::LIST_PAGE));
    }

    public function updateExperiment()
    {
        $idSite = $this->checkWriteRequest();
        $id = Request::fromRequest()->getIntegerParameter('id', 0);

        try {
            ApiRequest::processRequest('RebelExperiments.updateExperiment', ['id' => $id, 'idSite' => $idSite] + $this->getExperimentFields(), []);
            $this->notify('RebelExperiments_ExperimentUpdated', Notification::CONTEXT_SUCCESS);
        } catch (Exception $e) {
            $this->notify($e->getMessage(), Notification::CONTEXT_ERROR);
            $this->redirect(self::getReportingUrl($idSite, self::LIST_PAGE));
            return;
        }

        $this->redirect(self::getReportingUrl($idSite, self::LIST_PAGE));
    }

    public function stop()
    {
        $idSite = $this->checkWriteRequest();

        ApiRequest::processRequest('RebelExperiments.stopExperiment', ['id' => Request::fromRequest()->getIntegerParameter('id', 0), 'idSite' => $idSite], []);
        $this->notify('RebelExperiments_ExperimentStopped', Notification::CONTEXT_SUCCESS);

        $this->redirect(self::getReportingUrl($idSite, self::LIST_PAGE));
    }

    /**
     * Records the decision about an experiment, from the page of the experiment.
     */
    public function decide()
    {
        $idSite = $this->checkWriteRequest();
        $post = Request::fromPost();
        $id = Request::fromRequest()->getIntegerParameter('id', 0);

        try {
            ApiRequest::processRequest('RebelExperiments.setDecision', [
                'id' => $id,
                'idSite' => $idSite,
                'decision' => $post->getStringParameter('decision', ''),
                'note' => $post->getStringParameter('note', ''),
            ], []);
            $this->notify('RebelExperiments_DecisionSaved', Notification::CONTEXT_SUCCESS);
        } catch (Exception $e) {
            $this->notify($e->getMessage(), Notification::CONTEXT_ERROR);
        }

        $this->redirect(self::getReportingUrl($idSite, (string) $id));
    }

    /**
     * Shows a variant of an A/B test to everyone, from the page of the test.
     */
    public function rollOut()
    {
        $idSite = $this->checkWriteRequest();
        $request = Request::fromRequest();

        try {
            $idRollout = ApiRequest::processRequest('RebelExperiments.rollOutVariant', [
                'idSite' => $idSite,
                'id' => $request->getIntegerParameter('id', 0),
                'variant' => $request->getIntegerParameter('variant', 0),
            ], []);
            $this->notify('RebelExperiments_RolledOut', Notification::CONTEXT_SUCCESS);
        } catch (Exception $e) {
            $this->notify($e->getMessage(), Notification::CONTEXT_ERROR);
            $this->redirect(self::getReportingUrl($idSite, self::LIST_PAGE));
            return;
        }

        $this->redirect(self::getReportingUrl($idSite, (string) $idRollout));
    }

    public function delete()
    {
        $idSite = $this->checkWriteRequest();

        ApiRequest::processRequest('RebelExperiments.deleteExperiment', ['id' => Request::fromRequest()->getIntegerParameter('id', 0), 'idSite' => $idSite], []);
        $this->notify('RebelExperiments_ExperimentDeleted', Notification::CONTEXT_SUCCESS);

        $this->redirect(self::getReportingUrl($idSite, self::LIST_PAGE));
    }

    /**
     * The form to change an experiment. The list of experiments puts it in its own place.
     */
    public function editExperimentForm()
    {
        $idSite = self::checkWriteSite();

        return $this->renderTemplate('edit', self::getFormVariables($idSite) + [
            'experiment' => API::getInstance()->getExperiment(Request::fromRequest()->getIntegerParameter('id', 0), $idSite),
            'actionUrl' => self::getActionUrl('updateExperiment', $idSite),
            'backUrl' => self::getReportingUrl($idSite, self::LIST_PAGE),
        ]);
    }

    /**
     * The page of an experiment in the reporting menu.
     */
    public function result()
    {
        $this->checkSitePermission();
        $experiment = (new Experiments())->getById(Request::fromRequest()->getIntegerParameter('idExperiment', 0), $this->idSite);

        $segment = (string) ApiRequest::getRawSegmentFromRequest();
        $results = new Results();
        $result = $experiment ? $results->get($experiment, $segment) : null;
        $evolution = $experiment ? $results->getEvolution($experiment, $segment) : null;
        $canWrite = Piwik::isUserHasWriteAccess($this->idSite);

        if ($evolution !== null) {
            $idSite = $this->idSite;
            $evolution['chart'] = Chart::lines($evolution['series']);
            foreach ($evolution['chart']['grid'] as &$line) {
                $line['label'] = Results::format($evolution['unit'], $line['value'], $idSite);
            }
            foreach ($evolution['chart']['lines'] as &$line) {
                $line['labels'] = array_map(function (?float $value) use ($evolution, $idSite): string {
                    return $value === null ? '' : Results::format($evolution['unit'], $value, $idSite);
                }, $line['values']);
            }
        }

        return $this->renderTemplate('result', [
            'experiment' => $experiment,
            'audience' => $experiment ? Audience::describe($experiment['audience']) : '',
            'result' => $result,
            'evolution' => $evolution,
            'canWrite' => $canWrite,
            'canRollOut' => $experiment && $experiment['kind'] === ExperimentValidator::KIND_AB && $canWrite
                && (Piwik::isUserHasAdminAccess($this->idSite) || !ExperimentValidator::hasCode($experiment['variants'])),
            'rollOutUrl' => self::getActionUrl('rollOut', $this->idSite),
            'decideUrl' => self::getActionUrl('decide', $this->idSite),
            'decisions' => ExperimentValidator::DECISIONS,
            'nonce' => $canWrite ? Nonce::getNonce(self::NONCE) : '',
            'previewLinks' => $experiment && $canWrite ? $this->getPreviewLinks($experiment, $result['variants'] ?? []) : [],
        ]);
    }

    /**
     * What the tag on the website asks for, without being logged in: the experiments of a website
     * that are running. Nothing is told about experiments that have ended or not started.
     */
    public function getExperimentsPublic()
    {
        Common::sendHeader('Access-Control-Allow-Origin: *');
        Common::sendHeader('Content-Type: application/json; charset=utf-8');
        Common::sendHeader('Cache-Control: no-store');

        $request = Request::fromRequest();
        $now = Date::now()->getTimestamp();
        // A preview link carries the ID and the secret of an experiment, which may not be running.
        $preview = explode('-', $request->getStringParameter('preview', '')) + ['', ''];

        $running = [];
        foreach ((new Experiments())->getAllForSite($request->getIntegerParameter('idSite', 0)) as $experiment) {
            $isPreviewed = $preview[0] === (string) $experiment['id'] && hash_equals($experiment['preview_token'], $preview[1]);
            if (!$isPreviewed && Experiments::getStatus($experiment, $now) !== Experiments::STATUS_RUNNING) {
                continue;
            }
            $running[] = [
                'id' => $experiment['id'],
                'kind' => $experiment['kind'],
                'door' => $experiment['door'],
                'from_date' => $experiment['from_date'],
                'to_date' => $experiment['to_date'],
                'share' => $experiment['traffic_share'],
                'match' => $experiment['target_match'],
                'value' => $experiment['target_value'],
                'exclude_match' => $experiment['exclude_match'],
                'exclude_value' => $experiment['exclude_value'],
                'audience' => $experiment['audience'],
                'variants' => array_map(function (array $variant): array {
                    return ['changes' => $variant['changes'], 'redirect' => $variant['redirect'], 'css' => $variant['css'], 'js' => $variant['js']];
                }, $experiment['variants']),
            ];
        }

        return json_encode(['experiments' => $running]);
    }

    /**
     * @return int the website the request may change experiments of
     */
    private function checkWriteRequest(): int
    {
        $idSite = self::checkWriteSite();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new Exception(Piwik::translate('General_ExceptionSecurityCheckFailed'));
        }
        Nonce::checkNonce(self::NONCE, Request::fromPost()->getStringParameter('nonce', ''));

        return $idSite;
    }

    /**
     * Changes go through Matomo's API, so that plugins that listen to it, such as an audit log, see
     * them.
     *
     * @return array<string, mixed> the fields of the form, by the name the API has for them
     */
    private function getExperimentFields(): array
    {
        $post = Request::fromPost();
        $kind = $post->getStringParameter('kind', ExperimentValidator::KIND_AB);

        return [
            'name' => trim($post->getStringParameter('name', '')),
            'hypothesis' => trim($post->getStringParameter('hypothesis', '')),
            'description' => trim($post->getStringParameter('description', '')),
            'fromDate' => trim($post->getStringParameter('from_date', '')),
            'toDate' => trim($post->getStringParameter('to_date', '')),
            'variants' => array_values($post->getArrayParameter('variants', [])),
            'trafficShare' => $post->getIntegerParameter('traffic_share', 100),
            'targetMatch' => $post->getStringParameter('target_match', ExperimentValidator::MATCH_ANY),
            'targetValue' => $post->getStringParameter('target_value', ''),
            'primaryMeasure' => $this->getMeasure('primary_measure') ?? [],
            'kind' => $kind,
            'door' => $post->getArrayParameter($kind === ExperimentValidator::KIND_WATCH ? 'watch' : 'door', []),
            'guardrails' => array_values(array_filter(array_map([$this, 'getMeasure'], ['guardrail_0', 'guardrail_1']))),
            'confidence' => $post->getIntegerParameter('confidence', 95),
            'excludeMatch' => $post->getStringParameter('exclude_match', ExperimentValidator::EXCLUDE_NONE),
            'excludeValue' => $post->getStringParameter('exclude_value', ''),
            'notify' => $post->getStringParameter('notify', '') !== '',
            'audience' => $post->getArrayParameter('audience', []),
        ];
    }

    /**
     * Addresses that show a variant to the one who opens them, whatever the state of the
     * experiment, without counting the visit.
     *
     * @param array<string, mixed> $experiment
     * @param array<int, array<string, mixed>> $variants
     * @return array<string, string> the address by the name of the variant
     */
    private function getPreviewLinks(array $experiment, array $variants): array
    {
        $page = in_array($experiment['target_match'], ['equals', 'starts_with'], true)
            ? $experiment['target_value']
            : Site::getMainUrlFor($experiment['idsite']);

        if ($experiment['kind'] === ExperimentValidator::KIND_WATCH) {
            return [];
        }
        if ($experiment['kind'] === ExperimentValidator::KIND_FAKE_DOOR) {
            $variants = [['name' => Piwik::translate('RebelExperiments_Kind_fake_door'), 'variant' => 2]];
        }

        $links = [];
        foreach ($variants as $variant) {
            $links[$variant['name']] = $page . '#rexp-preview=' . $experiment['id'] . '-' . $variant['variant'] . '-' . $experiment['preview_token'];
        }

        return $links;
    }

    /**
     * @param string $field the name of the choice of a measure in the form
     * @return array<string, mixed>|null
     */
    private function getMeasure(string $field): ?array
    {
        $post = Request::fromPost();

        return Measures::fromChoice(
            $post->getStringParameter($field, ''),
            $post->getStringParameter($field . '_category', ''),
            $post->getStringParameter($field . '_action', '')
        );
    }

    private function notify(string $message, string $context): void
    {
        $notification = new Notification(Piwik::translate($message));
        $notification->context = $context;
        Notification\Manager::notify('RebelExperiments_result', $notification);
    }

    /**
     * @return array{idSite: int, period: string, date: string}
     */
    private static function getPageParameters(int $idSite): array
    {
        $request = Request::fromRequest();

        return [
            'idSite' => $idSite,
            'period' => $request->getStringParameter('period', 'day'),
            'date' => $request->getStringParameter('date', 'yesterday'),
        ];
    }

    /**
     * @param string $subcategory the page of the plugin in the reporting menu: one of the pages of
     *                            this class, or the ID of an experiment for its result
     */
    public static function getReportingUrl(int $idSite, string $subcategory): string
    {
        $page = self::getPageParameters($idSite);

        return Url::getCurrentUrlWithoutQueryString()
            . '?' . http_build_query(['module' => 'CoreHome', 'action' => 'index'] + $page)
            . '#?' . http_build_query($page + ['category' => 'RebelExperiments_RebelExperiments', 'subcategory' => $subcategory]);
    }

    private function redirect(string $url): void
    {
        Url::redirectToUrl($url);
    }
}
