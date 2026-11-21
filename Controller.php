<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting;

use Exception;
use Piwik\Common;
use Piwik\Date;
use Piwik\Nonce;
use Piwik\Notification;
use Piwik\Piwik;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Plugins\RebelABTesting\Tracker\ExposureParams;
use Piwik\Request;
use Piwik\Url;
use Piwik\ViewDataTable\Factory;

class Controller extends \Piwik\Plugin\Controller
{
    public const NONCE = 'RebelABTesting.experiment';

    private const LIST_PAGE = 'RebelABTesting_ExistingExperiments';
    private const CREATE_PAGE = 'RebelABTesting_CreateNewExperiment';

    /**
     * @return int the requested website when the user is admin of it, otherwise 0
     */
    public static function getAdminSite(): int
    {
        $idSite = Request::fromRequest()->getIntegerParameter('idSite', 0);

        return $idSite > 0 && Piwik::isUserHasAdminAccess($idSite) ? $idSite : 0;
    }

    public static function checkAdminSite(): int
    {
        $idSite = Request::fromRequest()->getIntegerParameter('idSite', 0);
        Piwik::checkUserHasAdminAccess($idSite);

        return $idSite;
    }

    public static function getActionUrl(string $action, int $idSite): string
    {
        return '?' . http_build_query(['module' => 'RebelABTesting', 'action' => $action] + self::getPageParameters($idSite));
    }

    public function addExperiment()
    {
        $idSite = $this->checkWriteRequest();

        try {
            API::getInstance()->insertExperiment($idSite, ...$this->getExperimentFields());
            $this->notify('RebelABTesting_ExperimentCreated', Notification::CONTEXT_SUCCESS);
        } catch (Exception $e) {
            $this->notify($e->getMessage(), Notification::CONTEXT_ERROR);
            $this->redirect($this->getReportingUrl($idSite, self::CREATE_PAGE));
            return;
        }

        $this->redirect($this->getReportingUrl($idSite, self::LIST_PAGE));
    }

    public function updateExperiment()
    {
        $idSite = $this->checkWriteRequest();
        $id = Request::fromRequest()->getIntegerParameter('id', 0);

        try {
            API::getInstance()->updateExperiment($id, $idSite, ...$this->getExperimentFields());
            $this->notify('RebelABTesting_ExperimentUpdated', Notification::CONTEXT_SUCCESS);
        } catch (Exception $e) {
            $this->notify($e->getMessage(), Notification::CONTEXT_ERROR);
            $this->redirect(Url::getCurrentUrlWithoutQueryString() . self::getActionUrl('editExperimentForm', $idSite) . '&id=' . $id);
            return;
        }

        $this->redirect($this->getReportingUrl($idSite, self::LIST_PAGE));
    }

    public function delete()
    {
        $idSite = $this->checkWriteRequest();

        API::getInstance()->deleteExperiment(Request::fromRequest()->getIntegerParameter('id', 0), $idSite);
        $this->notify('RebelABTesting_ExperimentDeleted', Notification::CONTEXT_SUCCESS);

        $this->redirect($this->getReportingUrl($idSite, self::LIST_PAGE));
    }

    public function editExperimentForm()
    {
        $idSite = self::checkAdminSite();

        $experiment = API::getInstance()->getExperiment(Request::fromRequest()->getIntegerParameter('id', 0), $idSite);

        return $this->renderTemplate('edit', [
            'experiment' => $experiment,
            'idSite' => $idSite,
            'updateUrl' => self::getActionUrl('updateExperiment', $idSite),
            'backUrl' => $this->getReportingUrl($idSite, self::LIST_PAGE),
            'nonce' => Nonce::getNonce(self::NONCE),
        ]);
    }

    /**
     * What the tag on the website asks for, without being logged in: the variant of an experiment
     * that is running. Nothing is told about experiments that have ended or not started.
     */
    public function getExperimentPublic()
    {
        Common::sendHeader('Access-Control-Allow-Origin: *');
        Common::sendHeader('Content-Type: application/json; charset=utf-8');
        Common::sendHeader('Cache-Control: no-store');

        $request = Request::fromRequest();
        $row = (new Experiments())->getById($request->getIntegerParameter('id', 0), $request->getIntegerParameter('idSite', 0));

        if (!ExposureParams::isWithinSchedule(Date::now()->getTimestamp(), $row)) {
            return json_encode(['found' => false]);
        }

        return json_encode(API::publicFields($row));
    }

    public function getExperimentReport()
    {
        $this->checkSitePermission();

        // The third argument keeps sorting and paging of the report on this action. Without it they
        // would go to getExperimentData, which names the first column after the variant.
        $view = Factory::build('table', 'RebelABTesting.getExperimentData', 'RebelABTesting.getExperimentReport');
        $view->config->columns_to_display = ['label', 'nb_visits'];
        $view->config->addTranslation('label', Piwik::translate('RebelABTesting_ExperimentName'));
        $view->config->addTranslation('nb_visits', Piwik::translate('RebelABTesting_NbVisits'));
        $view->config->title = Piwik::translate('RebelABTesting_ExperimentsReport');
        $view->config->documentation = Piwik::translate('RebelABTesting_ReportHelpText');
        $view->requestConfig->filter_sort_column = 'nb_visits';
        $view->requestConfig->filter_sort_order = 'desc';

        return $view->render();
    }

    /**
     * The variants of an experiment, shown when its row in the report is opened.
     */
    public function getExperimentData()
    {
        $this->checkSitePermission();

        $view = Factory::build('table', 'RebelABTesting.getExperimentData');
        $view->config->columns_to_display = ['label', 'nb_visits'];
        $view->config->addTranslation('label', Piwik::translate('RebelABTesting_Variant'));
        $view->config->addTranslation('nb_visits', Piwik::translate('RebelABTesting_NbVisits'));
        $view->requestConfig->filter_sort_column = 'nb_visits';
        $view->requestConfig->filter_sort_order = 'desc';

        return $view->render();
    }

    /**
     * @return int the website the request may change experiments of
     */
    private function checkWriteRequest(): int
    {
        $idSite = self::checkAdminSite();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new Exception(Piwik::translate('General_ExceptionSecurityCheckFailed'));
        }
        Nonce::checkNonce(self::NONCE, Request::fromPost()->getStringParameter('nonce', ''));

        return $idSite;
    }

    /**
     * The fields of the form, in the order the API takes them.
     *
     * @return string[]
     */
    private function getExperimentFields(): array
    {
        $post = Request::fromPost();
        $fields = [preg_replace('/[^a-zA-Z0-9_-]/', '', $post->getStringParameter('name', ''))];

        foreach (['hypothesis', 'description', 'from_date', 'to_date', 'css_insert', 'js_insert'] as $field) {
            $fields[] = trim($post->getStringParameter($field, ''));
        }

        return $fields;
    }

    private function notify(string $message, string $context): void
    {
        $notification = new Notification(Piwik::translate($message));
        $notification->context = $context;
        Notification\Manager::notify('RebelABTesting_result', $notification);
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

    private function getReportingUrl(int $idSite, string $subcategory): string
    {
        $page = self::getPageParameters($idSite);

        return Url::getCurrentUrlWithoutQueryString()
            . '?' . http_build_query(['module' => 'CoreHome', 'action' => 'index'] + $page)
            . '#?' . http_build_query($page + ['category' => 'RebelABTesting_RebelABTesting', 'subcategory' => $subcategory]);
    }

    private function redirect(string $url): void
    {
        Url::redirectToUrl($url);
    }
}
