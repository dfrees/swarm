<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Config;

/**
 * Defines values for configuration values that can be at a global level and either allowed
 * or disallowed to be overridden at lower levels.
 * @package Application\Config
 */
class Setting
{
    // Possible states
    const ENABLED         = 'Enabled';        // Globally enabled but can be overridden by other settings
    const DISABLED        = 'Disabled';       // Globally disabled but can be overridden by other settings
    const FORCED_ENABLED  = 'ForcedEnabled';  // Globally enabled cannot be overridden by other settings
    const FORCED_DISABLED = 'ForcedDisabled'; // Globally disabled cannot be overridden by other settings
}
