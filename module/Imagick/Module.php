<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Imagick;

use Files\Format\Handler as FormatHandler;
use Laminas\Mvc\MvcEvent;

class Module
{
    /**
     * Add a preview handler for types supported by Image Magick
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $formats     = $services->get('formats');

        $formats->addHandler(
            new FormatHandler(
                // can-preview callback
                function ($file, $extension, $mimeType, $request) {
                    if (!extension_loaded('imagick')) {
                        return false;
                    }

                    // support an explicit set of formats if imagick does
                    $formats = array_intersect(
                        ['bmp', 'eps', 'psd', 'tga', 'tiff'],
                        array_map('strtolower', \Imagick::queryFormats())
                    );

                    // special case for ".tif" files, which are identical to ".tiff" files in all but name
                    if (in_array('tiff', $formats)) {
                        $formats[] = 'tif';
                    }

                    if (in_array($extension, $formats)) {
                        return true;
                    }
                },
                // render-preview callback
                function ($file, $extension, $mimeType, $request) use ($services) {
                    $helpers   = $services->get('ViewHelperManager');
                    $escapeUrl = $helpers->get('escapeUrl');
                    $url       = $helpers->get('url');
                    $viewUrl   = $url('imagick', ['path' => trim($file->getDepotFilename(), '/')])
                                . '?v=' . $escapeUrl($file->getRevspec());

                    return '<div class="view view-image img-polaroid pull-left">'
                         .  '<img src="' . $viewUrl . '">'
                         . '</div>';
                }
            ),
            'imagick'
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
