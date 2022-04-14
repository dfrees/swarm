<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Markdown;

/**
 * Define common settings for Markdown/Parsedown
 */
class Settings
{
    const DISABLED                = 'disabled';
    const ENABLED                 = 'enabled';
    const SAFE                    = 'safe';
    const UNSAFE                  = 'unsafe';
    const MARKDOWN_OPTIONS        = [self::DISABLED, self::SAFE, self::UNSAFE];
    const DEFAULT_FILE_EXTENSIONS = ['md', 'markdown', 'mdown', 'mkdn', 'mkd', 'mdwn', 'mdtxt', 'mdtext'];
    // Legacy values from project readme settings
    const UNRESTRICTED            = 'unrestricted';
    const RESTRICTED              = 'restricted';
    const LEGACY_MARKDOWN_OPTIONS = [self::ENABLED, self::DISABLED, self::RESTRICTED, self::UNRESTRICTED];
}
