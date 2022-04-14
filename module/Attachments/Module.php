<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Attachments;

use Application\Filter\ShorthandBytes;

class Module
{
    public function getConfig()
    {
        $config = include __DIR__ . '/config/module.config.php';

        // set default max size to php's upload_max_filesize (in bytes - e.g., "8M" must be converted to 8388608)
        if (empty($config['attachments']['max_file_size'])) {
            $config['attachments']['max_file_size'] = ShorthandBytes::toBytes(ini_get('upload_max_filesize'));
        }

        return $config;
    }
}
