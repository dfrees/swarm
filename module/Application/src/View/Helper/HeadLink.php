<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

use Laminas\View\Helper\HeadLink as ZendHeadLink;
use Application\Helper\VersionTrait as VersionTrait;

class HeadLink extends ZendHeadLink
{
    use VersionTrait;
    protected $customAdded = false;
    protected $services    = null;

    /**
     * AbstractHelper constructor.
     * @param $services
     */
    public function __construct($services)
    {
        $this->services = $services;
        parent::__construct();
    }

    /**
     * Retrieve string representation
     * Extends parent to add in custom styles before going to string.
     *
     * @param  string|int   $indent     Amount of whitespaces or string to use for indention
     * @return string       the head script(s)
     */
    public function toString($indent = null)
    {
        // if we haven't already added the custom stylesheets do so now
        if (!$this->customAdded) {
            $this->customAdded = true;

            // get the base path as we'll need it later
            $services = $this->services;
            $config   = $services->get('config') + ['css' => []];
            $dev      = isset($config['environment']['mode']) && $config['environment']['mode'] == 'development';
            $accept   = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
            $gz       = strpos($accept, 'gzip') !== false ? 'gz' : '';
            $basePath = $services->get('ViewHelperManager')->get('assetBasePath')->__invoke();

            // first deal with config declared css
            foreach (array_reverse($config['css']) as $key => $value) {
                // if key is a string, its a 'build', use that if we aren't in dev mode and it exists
                if (is_string($key) && !$dev && is_file(BASE_PATH . '/public' . $key . $gz)) {
                    // if the file is under build; mix in the patch level (assuming we have one)
                    // this serves as a 'cache-buster' to ensure browsers take upgraded versions
                    if (defined('VERSION_PATCHLEVEL') && ctype_digit((string) VERSION_PATCHLEVEL)) {
                        $key = preg_replace(
                            '#^(/build/.+)\.css$#',
                            '$1-' . VERSION_PATCHLEVEL . '.css',
                            $key
                        );
                    }

                    $this->prependStylesheet($this->getVersionedFile($basePath . $key . $gz), 'all');
                    continue;
                }

                // we're not using a build so add all 'value' css scripts
                $value = array_reverse((array) $value);
                foreach ($value as $script) {
                    $this->prependStylesheet($this->getVersionedFile($basePath .  $script), 'all');
                }
            }

            // find any custom css to be added
            $files = array_merge(
                glob(BASE_PATH . '/public/custom/*.css'),
                glob(BASE_PATH . '/public/custom/*/*.css')
            );

            // sort the files to ensure a predictable order
            natcasesort($files);

            foreach ($files as $file) {
                $custom = substr($file, strlen(BASE_PATH . '/public/'));
                $this->appendStylesheet($this->getVersionedFile($basePath . '/' . $custom), 'all');
            }
        }

        return parent::toString($indent);
    }
}
