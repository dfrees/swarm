<?php
/**
 * Perforce Swarm, Community Development
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Markdown;

use Application\View\Helper\ViewHelperFactory;
use Application\Config\ConfigManager;
use Files\Format\Handler as FormatHandler;
use P4\File\File;
use Laminas\Mvc\MvcEvent;

class Module
{
    /**
     * Add a preview handler for markdown files in the file browser.
     * Note that files > 1MB will be cropped for performance reasons.
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $formats     = $services->get('formats');

        $formats->addHandler(
            new FormatHandler(
                // can-preview callback
                function ($file, $extension, $mimeType, $request) use ($services) {
                    // Returning false here will mean we never render markdown for diffs and file
                    // preview. We need to improve markdown display configuration with regards to
                    // the actual display and any size limit and how that effects display for
                    // diff vs view vs project overview. Note this does not effect the display of
                    // markdown in the project overview.
                    //
                    // see https://jira.perforce.com:8443/browse/SW-4196
                    // see https://jira.perforce.com:8443/browse/SW-4191
                    if (strpos($request->getUri()->getPath(), '/files') !== false) {
                        $config = $services->get('config');
                        return ConfigManager::getValue(
                            $config,
                            ConfigManager::MARKDOWN_MARKDOWN
                        ) !== Settings::DISABLED &&
                            in_array(
                                $extension,
                                ConfigManager::getValue($config, ConfigManager::MARKDOWN_FILE_EXTENSIONS)
                            );
                    }
                    return false;
                },
                // render-preview callback
                function ($file, $extension, $mimeType) use ($services) {
                    $helpers          = $services->get('ViewHelperManager');
                    $purifiedMarkdown = $helpers->get(ViewHelperFactory::MARKUP);

                    $contents = $file->getDepotContents(
                        [
                            File::UTF8_CONVERT  => true,
                            File::UTF8_SANITIZE => true,
                            File::MAX_SIZE  => File::MAX_SIZE_VALUE
                        ]
                    );

                    return '<div class="view view-md markdown">'
                    .   $purifiedMarkdown($contents)
                    .  '</div>';
                }
            ),
            'markdown'
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
