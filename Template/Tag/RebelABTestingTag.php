<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelABTesting\Template\Tag;

use Piwik\Piwik;
use Piwik\Settings\FieldConfig;
use Piwik\Plugins\TagManager\Template\Tag\BaseTag;
use Piwik\Plugins\RebelABTesting\Dao\Experiments;
use Piwik\Request;
use Piwik\Validators\NotEmpty;

/**
 * The tag only knows which experiment it is for. What the experiment does is fetched from Matomo
 * when the tag fires, so a change to the experiment needs no new version of the container.
 */
class RebelABTestingTag extends BaseTag
{
    public function getName()
    {
        return Piwik::translate('RebelABTesting_TagName');
    }

    public function getIcon()
    {
        return 'plugins/RebelABTesting/assets/rebel-ab-testing.svg';
    }

    public function getParameters()
    {
        $defaultOrigin = $this->detectMatomoOrigin();

        return array(
            $this->makeSetting('experiment', '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
                $field->title = Piwik::translate('RebelABTesting_TagChooseExperiment');
                $field->availableValues = $this->getExperiments();
                $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;
                $field->description = Piwik::translate('RebelABTesting_TagDescription');
                // An experiment that was deleted must not stop the container from being published.
                $field->validate = function ($value) {
                };
            }),
            $this->makeSetting(
                'matomoOrigin',
                $defaultOrigin,
                FieldConfig::TYPE_STRING,
                function (FieldConfig $field) {
                    $field->title = Piwik::translate('RebelABTesting_TagMatomoOrigin');
                    $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
                    $field->description = Piwik::translate('RebelABTesting_TagMatomoOriginDescription');
                    $field->validators[] = new NotEmpty();
                    $field->validate = function ($value) {
                        $value = (string) $value;
                        if (!preg_match('#^https?://[a-zA-Z0-9.\-]+(:[0-9]+)?$#', $value)) {
                            throw new \Exception(Piwik::translate('RebelABTesting_TagMatomoOriginInvalid'));
                        }
                    };
                }
            ),
        );
    }

    public function getCategory()
    {
        return self::CATEGORY_DEVELOPERS;
    }

    /**
     * @return array<string, string> the name of each experiment of the website, by "id,idSite"
     */
    private function getExperiments(): array
    {
        $idSite = Request::fromRequest()->getIntegerParameter('idSite', 0);
        $options = [];
        foreach ((new Experiments())->getAllForSite($idSite) as $experiment) {
            $options[$experiment['id'] . ',' . $idSite] = $experiment['name'];
        }

        return $options;
    }

    /**
     * The origin of this Matomo, so that it does not have to be typed in.
     */
    private function detectMatomoOrigin(): string
    {
        try {
            $url = \Piwik\SettingsPiwik::getPiwikUrl();
            if (!is_string($url) || $url === '') {
                return '';
            }
            $parts = parse_url($url);
            if (empty($parts['scheme']) || empty($parts['host'])) {
                return '';
            }
            $origin = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            return $origin;
        } catch (\Throwable $e) {
            return '';
        }
    }
}
