<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Groups\Model;

use Record\Key\AbstractKey;

class Config extends AbstractKey
{
    const KEY_PREFIX = 'swarm-group-';

    const GROUP_NOTIFICATION_SETTINGS = 'group_notification_settings';
    const FIELD_USE_MAILING_LIST      = 'useMailingList';
    const FIELD_EMAIL_FLAGS           = 'emailFlags';
    const FIELD_EMAIL_ADDRESS         = 'emailAddress';
    const FIELD_DESCRIPTION           = 'description';

    protected $fields = [
        'name'          => [
            'accessor'  => 'getName',
            'mutator'   => 'setName'
        ],
        self::FIELD_DESCRIPTION => [
            'accessor'  => 'getDescription',
            'mutator'   => 'setDescription'
        ],
        self::FIELD_USE_MAILING_LIST,
        self::FIELD_EMAIL_ADDRESS,
        self::FIELD_EMAIL_FLAGS => [
            'accessor'  => 'getEmailFlags',
            'mutator'   => 'setEmailFlags'
        ],
        Config::GROUP_NOTIFICATION_SETTINGS => [
            'accessor'  => 'getNotificationSettings',
            'mutator'   => 'setNotificationSettings'
        ],
    ];

    /**
     * Gets the group notification settings as an array.
     * @return array|mixed
     */
    public function getNotificationSettings()
    {
        $settings = $this->getRawValue(static::GROUP_NOTIFICATION_SETTINGS);
        if (!is_array($settings)) {
            $settings = json_decode($settings, true);
        }
        return $settings !== null && $settings !== false ? $settings : null;
    }

    /**
     * Sets the group notification settings. The settings are stored raw but we only support
     * array or JSON that can be represented as an array when returning them.
     * @param $settings
     * @return AbstractKey
     */
    public function setNotificationSettings($settings)
    {
        return $this->setRawValue('group_notification_settings', $settings);
    }


    /**
     * Get the friendlier name for the group.
     *
     * @return  string  group name
     */
    public function getName()
    {
        return strlen($this->getRawValue('name'))
            ? $this->getRawValue('name')
            : $this->getId();
    }

    /**
     * Set a friendlier name for the group.
     *
     * @param   string|null     $name   the name for the group or null
     * @return  AbstractKey             to maintain a fluent interface
     */
    public function setName($name)
    {
        return $this->setRawValue('name', $name);
    }

    /**
     * The description for the group.
     *
     * @return  string|null     the description for the group
     */
    public function getDescription()
    {
        return $this->getRawValue('description');
    }

    /**
     * Set a description for the group.
     *
     * @param   string|null     $description    the description for the group or null
     * @return  AbstractKey                     to maintain a fluent interface
     */
    public function setDescription($description)
    {
        return $this->setRawValue('description', $description);
    }

    /**
     * Returns an array of email/notification flags.
     *
     * @return  array   names for all email flags
     */
    public function getEmailFlags()
    {
        return array_map(
            function ($flag) {
                return (bool) $flag;
            },
            (array) $this->getRawValue(self::FIELD_EMAIL_FLAGS)
        );
    }

    /**
     * Returns the value of the specified email flag, if it exists, or null if it could not be found.
     *
     * @param   string      $flag   specific email flag we are looking for
     * @return  mixed|null  value of the flag if found, or null if the flag was not found
     */
    public function getEmailFlag($flag)
    {
        $emailFlags = $this->getEmailFlags();
        return isset($emailFlags[$flag]) ? $emailFlags[$flag] : null;
    }

    /**
     * Set an array of active email/notification flags.
     *
     * @param   array|null  $flags    an array of flags or null
     * @return  AbstractKey           to maintain a fluent interface
     */
    public function setEmailFlags($flags)
    {
        return $this->setRawValue(self::FIELD_EMAIL_FLAGS, (array) $flags);
    }
}
