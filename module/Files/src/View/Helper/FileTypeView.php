<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\View\Helper;

use Application\Config\ConfigManager;
use Application\View\Helper\AbstractHelper;

class FileTypeView extends AbstractHelper
{
    const VIEW_FORMAT = 'format';
    const VIEW_LABEL  = 'label';

    /**
     * Provides a hint to the File view with respect to the availability of a file type specific format
     *
     * @param $file         the file object being viewed
     * @param $request      the view file http request
     * @return  array      the configuration for any special view applicable to this file type
     * @throws \Application\Config\ConfigException
     */
    public function __invoke($file, $request)
    {
        return $this->getSpecialViewConfig($this->services, $file, $request);
    }

    /**
     * Determine whether files with this extension have a currently active formatter.
     * @param $services     configured application services
     * @param $file         the file object being viewed
     * @param $request      the view file http request
     * @return bool|array   false|the view configuration
     * @throws \Application\Config\ConfigException
     */

    private function getSpecialViewConfig($services, $file, $request)
    {
        $config       = $services->get('config');
        $handlers     = $services->get('formats')->getHandlers();
        $translator   = $services->get('translator');
        $specialViews = [
            ConfigManager::MARKDOWN => [
                ConfigManager::FILE_EXTENSIONS => ConfigManager::getValue(
                    $config,
                    ConfigManager::MARKDOWN_FILE_EXTENSIONS
                ),
                'viewConfig' => [
                    self::VIEW_FORMAT => ConfigManager::MARKDOWN,
                    self::VIEW_LABEL  => $translator->t('Markdown')
                ]
            ]
        ];
        foreach ($specialViews as $view) {
            if (in_array($file->getExtension(), $view[ConfigManager::FILE_EXTENSIONS])) {
                $config = $view['viewConfig'];
                if ($handlers[$config[self::VIEW_FORMAT]] &&
                    $handlers[$config[self::VIEW_FORMAT]]->canPreview($file, $request)
                ) {
                    return $view['viewConfig'];
                }
            }
        }
        return false;
    }
}
