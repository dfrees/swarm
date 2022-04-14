<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Groups\Model;

interface IGroup
{
    const GROUP           = 'group';
    const FIELD_NAME      = 'name';
    const FIELD_CONFIG    = 'config';
    const FIELD_ID        = 'id';
    const IDS             = 'ids';
    const EXPAND          = 'expand';
    const FETCH_BY_ID     = self::IDS;
    const FETCH_BY_EXPAND = self::EXPAND;
    const CONFIG_FIELDS   = [
                                IGroup::FIELD_NAME,
                                Config::FIELD_DESCRIPTION,
                                Config::FIELD_EMAIL_FLAGS,
                                Config::GROUP_NOTIFICATION_SETTINGS,
                                Config::FIELD_EMAIL_ADDRESS,
                                Config::FIELD_USE_MAILING_LIST
                            ];
}
