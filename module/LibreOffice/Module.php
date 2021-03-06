<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace LibreOffice;

use Files\Format\Handler as FormatHandler;
use Laminas\Mvc\MvcEvent;

class Module
{
    /**
     * Add a preview handler for types supported by LibreOffice
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $config      = $services->get('config');
        $formats     = $services->get('formats');

        $formats->addHandler(
            new FormatHandler(
                // can-preview callback
                function ($file, $extension, $mimeType, $request) use ($config) {
                    $extensions = [
                        'doc', 'docx', 'key', 'numbers', 'pages', 'ppt', 'pptx', 'rtf', 'vsd', 'xls', 'xlsx'
                    ];
                    if (!in_array($extension, $extensions)) {
                        return false;
                    }

                    // good to go if LibreOffice is installed
                    $soffice = $config['libreoffice']['path'];
                    exec('which ' . escapeshellarg($soffice), $output, $result);
                    return !(bool) $result;
                },
                // render-preview callback
                function ($file, $extension, $mimeType, $request) use ($services) {
                    $helpers   = $services->get('ViewHelperManager');
                    $escapeUrl = $helpers->get('escapeUrl');
                    $url       = $helpers->get('url');
                    $te        = $helpers->get('te');
                    $viewUrl   = $url('libreoffice', ['path' => trim($file->getDepotFilename(), '/')])
                                . '?v=' . $escapeUrl($file->getRevspec());

                    return '<div class="view view-pdf img-polaroid">'
                        .   '<object width="100%" height="100%" type="application/pdf" data="' . $viewUrl . '">'
                        .    '<p>' . $te('It appears you don\'t have a pdf plugin for this browser.') . '</p>'
                        .   '</object>'
                        .  '</div>';
                }
            ),
            'libreoffice'
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
