<?php
/**
 * Specific Exception for configuration issues.
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Config;

class ConfigException extends \Exception
{
    // Code for path does not exist
    const PATH_DOES_NOT_EXIST = 1;
}
