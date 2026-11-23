<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelExperiments\Template\Tag;

use Piwik\Piwik;
use Piwik\Plugins\TagManager\Template\Tag\BaseTag;
use Piwik\Settings\FieldConfig;
use Piwik\SettingsPiwik;
use Piwik\Validators\NotEmpty;
use Piwik\Validators\NumberRange;

/**
 * Runs the experiments of the website the container belongs to. Which experiments there are is
 * fetched from Matomo in the browser, so a new experiment needs no new version of the container.
 */
class RebelExperimentsTag extends BaseTag
{
    private const MAX_HIDE_TIMEOUT = 5000;

    public function getName()
    {
        return Piwik::translate('RebelExperiments_TagName');
    }

    public function getDescription()
    {
        return Piwik::translate('RebelExperiments_TagDescription');
    }

    public function getIcon()
    {
        return 'plugins/RebelExperiments/assets/rebel-experiments.svg';
    }

    public function getParameters()
    {
        return [
            $this->makeSetting('matomoUrl', rtrim((string) SettingsPiwik::getPiwikUrl(), '/'), FieldConfig::TYPE_STRING, function (FieldConfig $field) {
                $field->title = Piwik::translate('RebelExperiments_TagMatomoOrigin');
                $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
                $field->description = Piwik::translate('RebelExperiments_TagMatomoOriginDescription');
                $field->validators[] = new NotEmpty();
                $field->validate = function ($value) {
                    if (!preg_match('#^https?://[a-zA-Z0-9.\-]+(:[0-9]+)?(/[A-Za-z0-9._~/-]*)?$#', (string) $value)) {
                        throw new \Exception(Piwik::translate('RebelExperiments_TagMatomoOriginInvalid'));
                    }
                };
            }),
            $this->makeSetting('hideTimeout', 500, FieldConfig::TYPE_INT, function (FieldConfig $field) {
                $field->title = Piwik::translate('RebelExperiments_TagHideTimeout');
                $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
                $field->description = Piwik::translate('RebelExperiments_TagHideTimeoutDescription');
                $field->validators[] = new NumberRange(0, self::MAX_HIDE_TIMEOUT);
            }),
        ];
    }

    public function getCategory()
    {
        return self::CATEGORY_DEVELOPERS;
    }
}
