<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Markdown\View\Helper;

use Application\Config\ConfigManager;
use Application\View\Helper\AbstractHelper;
use Markdown\Settings;
use Parsedown;

class MarkupMarkdown extends AbstractHelper
{
    /**
     * Generates html from the supplied markdown text.
     * @param  string $value   markdown text to be parsed
     * @return string          parsed result
     * @throws \Application\Config\ConfigException
     */
    public function __invoke($value)
    {
        return $this->markdown($value);
    }

    /**
     * Depending on settings in the config.php depends how we render the readme.
     * @param  string    $value     The value to convert
     * @return string               The converted value according to markdown settings or an empty string if there is
     *                              no conversion.
     * @throws \Application\Config\ConfigException
     */
    public function markdown($value)
    {
        // If readme config is set switch to check if "safe" or "unsafe" is set and escape html on
        // restricted. If disabled we will use default and not return readme file.
        $parseDown = new Parsedown();
        $config    = $this->services->get('config');
        $setting   = ConfigManager::getValue($config, ConfigManager::MARKDOWN_MARKDOWN, Settings::SAFE);
        switch ($setting) {
            case Settings::SAFE:
                $parseDown->setMarkupEscaped(true);
                break;
            case Settings::UNSAFE:
                break;
            default:
                return '';
        }
        return $parseDown->text($value);
    }
}
