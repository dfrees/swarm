<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Helper;

trait VersionTrait
{

    /**
     * Adds a version parameter to a script to prevent caching outdated scripts during upgrade process
     *
     * @param string $file          - The name of the file
     * @param $emulatedVersion      - version of the file when using PHPUnit tests
     * @return string               - The name of the file with the version appended
     */
    public function getVersionedFile(string $file, $emulatedVersion = null)
    {
        $version = 1; // Default to 1 if the following code is invalid
        if ($emulatedVersion !== null) {
            if (ctype_digit((string)$emulatedVersion) && $emulatedVersion >= 1) {
                $version = $emulatedVersion;
            }
        } elseif (defined('VERSION_PATCHLEVEL') && ctype_digit((string)VERSION_PATCHLEVEL) && VERSION_PATCHLEVEL >= 1) {
            $version = VERSION_PATCHLEVEL;
        }
        return $file . "?v=" . $version;
    }
}
