<?php
/**
 * Created by PhpStorm.
 * User: drobins
 * Date: 15/09/2017
 * Time: 16:55
 */

namespace Groups\View\Helper;

use Application\Config\Setting;
use Laminas\View\Helper\AbstractHelper;
use Notifications\Settings;

class NotificationSettings extends AbstractHelper
{
    const TITLE = "title";
    const ID    = "id";

    const NOTIFY_WHEN = 'Email group members when:';
    const RESET       = 'Reset to default';
    const CHECK_ALL   = 'Check all';

    const REVIEW_NEW               = 'a review is started in the project';
    const REVIEW_CHANGELIST_COMMIT = 'a review or change is committed';
    const REVIEW_FILES             = 'files in the review are updated';
    const REVIEW_TESTS             = 'tests on the review have finished';
    const REVIEW_VOTE              = 'a vote is cast on a review';
    const REVIEW_STATE             = 'the state of the review changes';
    const REVIEW_JOIN_LEAVE        = 'someone joins or leaves the review';
    const REVIEW_COMMENT_NEW       = 'a comment is made on the review';
    const REVIEW_COMMENT_UPDATE    = 'a comment on the review is updated';


    const IS_MEMBER_TITLE    = 'The group is a member of a project, and';
    const IS_REVIEWER_TITLE  = 'The group is a reviewer on a review, and';
    const IS_MODERATOR_TITLE = 'The group is a moderator on a project, and';

    /**
     * This is not ideal. In order for values to be picked up for .po generation strings
     * must be specifically mentioned with a call to a function that returns a string
     * (even though it will actually never be called).
     * We are forced to repeat the values for consts above (in this case only the group
     * specific ones as User/NotificationSettings.php lists the common values).
     */
    private static function msgIds()
    {
        NotificationSettings::t('The group is a member of a project, and');
        NotificationSettings::t('The group is a reviewer on a review, and');
        NotificationSettings::t('The group is a moderator on a project, and');
        NotificationSettings::t('a review is started in the project');
        NotificationSettings::t('Email group members when:');
    }

    /**
     * Dummy translation.
     * @param $value
     * @return mixed
     */
    private static function t($value)
    {
        return $value;
    }

    // These are the settings to be displayed on group page.
    // If you add additional notification setting here ensure
    // you add them to userSettings as well.
    public static $displaySettings = [

        Settings::IS_MEMBER => [
            self::TITLE => self::IS_MEMBER_TITLE,
            'settings'  => [
                [
                    self::ID    => Settings::REVIEW_NEW,
                    self::TITLE => self::REVIEW_NEW,
                ],
                [
                    self::ID    => Settings::REVIEW_CHANGELIST_COMMIT,
                    self::TITLE => self::REVIEW_CHANGELIST_COMMIT,
                ],
            ]
        ],

        Settings::IS_REVIEWER => [
            self::TITLE => self::IS_REVIEWER_TITLE,
            'settings' => [
                [
                    self::ID    => Settings::REVIEW_FILES,
                    self::TITLE => self::REVIEW_FILES,
                ],
                [
                    self::ID    => Settings::REVIEW_TESTS,
                    self::TITLE => self::REVIEW_TESTS,
                ],
                [
                    self::ID    => Settings::REVIEW_VOTE,
                    self::TITLE => self::REVIEW_VOTE,
                ],
                [
                    self::ID    => Settings::REVIEW_STATE,
                    self::TITLE => self::REVIEW_STATE,
                ],
                [
                    self::ID    => Settings::REVIEW_JOIN_LEAVE,
                    self::TITLE => self::REVIEW_JOIN_LEAVE,
                ],
                [
                    self::ID    => Settings::REVIEW_CHANGELIST_COMMIT,
                    self::TITLE => self::REVIEW_CHANGELIST_COMMIT,
                ],
                [
                    self::ID    => Settings::REVIEW_COMMENT_NEW,
                    self::TITLE => self::REVIEW_COMMENT_NEW,
                ],
                [
                    self::ID    => Settings::REVIEW_COMMENT_UPDATE,
                    self::TITLE => self::REVIEW_COMMENT_UPDATE,
                ]
            ]
        ],

        Settings::IS_MODERATOR => [
            self::TITLE => self::IS_MODERATOR_TITLE,
            'settings'  => [
                [
                    self::ID    => Settings::REVIEW_FILES,
                    self::TITLE => self::REVIEW_FILES,
                ],
                [
                    self::ID    => Settings::REVIEW_TESTS,
                    self::TITLE => self::REVIEW_TESTS,
                ],
                [
                    self::ID    => Settings::REVIEW_CHANGELIST_COMMIT,
                    self::TITLE => self::REVIEW_CHANGELIST_COMMIT,
                ],
            ]
        ],
    ];

    // This array is required for the settings to be built and
    // passed to helper.
    public static $settings = [
        Settings::REVIEW_NEW => [
            'settings'  => [
                [
                    self::ID    => Settings::IS_MEMBER,
                ]
            ]
        ],
        Settings::REVIEW_COMMENT_NEW => [
            'settings'  => [
                [
                    self::ID    => Settings::IS_REVIEWER,
                ],
            ]
        ],
        Settings::REVIEW_COMMENT_UPDATE => [
            'settings'  => [
                [
                    self::ID    => Settings::IS_REVIEWER,
                ],
            ]
        ],
        Settings::REVIEW_FILES => [
            'settings'  => [
                [
                    self::ID    => Settings::IS_REVIEWER,
                ],
                [
                    self::ID    => Settings::IS_MODERATOR,
                ],
            ]
        ],
        Settings::REVIEW_TESTS => [
            'settings'  => [
                [
                    self::ID    => Settings::IS_REVIEWER,
                ],
                [
                    self::ID    => Settings::IS_MODERATOR,
                ]

            ]
        ],
        Settings::REVIEW_VOTE => [
            'settings'  => [
                [
                    self::ID    => Settings::IS_REVIEWER,
                ]
            ]
        ],
        Settings::REVIEW_STATE => [
            'settings'  => [
                [
                    self::ID    => Settings::IS_REVIEWER,
                ]
            ]
        ],
        Settings::REVIEW_JOIN_LEAVE => [
            'settings' => [
                [
                    self::ID    => Settings::IS_REVIEWER,
                ]
            ]
        ],
        Settings::REVIEW_CHANGELIST_COMMIT => [
            'settings'  => [
                [
                    self::ID    => Settings::IS_REVIEWER,
                ],
                [
                    self::ID    => Settings::IS_MODERATOR,
                ],
                [
                    self::ID    => Settings::IS_MEMBER,
                ]
            ]
        ],
    ];

    /**
     * Provides a table of setting for the user page current viewing.
     *
     * @param $settings     // get the settings passed in.
     * @return HTML Object  // return back the HTML object of the table
     */
    public function __invoke($settings)
    {
        return ''
          . '<table id="notificationTable" class="table table-hover table-striped">'
          .   '<thead><tr><th colspan="2">' . $this->buildTableHeader($this->getView()) . '</th></tr></thead>'
          .   $this->buildTableBody($this->getView(), $settings)
          . '</table>';
    }

    /**
     * Build the top line of the notification settings table
     *
     * @param $view the current view object, essentially the page helper
     *
     * @return string the contents of the <th> tag
     */
    public function buildTableHeader($view)
    {
        return ''
            . '<div id="groupNotificationHeader">'
            .   '<h3 class="notificationTitle pull-left">' . $view->te(self::NOTIFY_WHEN) . '</h3>'
            .   '<div class="notificationController pull-right">'
            .     '<div class="control-group pull-right">'
            .       '<div class="controls">'
            .         '<a id="notificationReset" name="reset" class="btn pull-right">'
            .             '<i class="icon-repeat"></i>' . $view->te(self::RESET)
            .         '</a>'
            .         '<div class="checkAll">'
            .           '<label>' . $view->te(self::CHECK_ALL)
            .             '<input id="checkAllNotifications" type="checkbox" data-default="false">'
            .           '</label>'
            .         '</div>'
            .       '</div>'
            .     '</div>'
            .   '</div>'
            . '</div>';
    }

    /**
     * Build the table body based on user settings otherwise use default settings
     *
     * @param $view         To allow us to use the translate on the title we require the view.
     * @param $settings     Both the users and default settings are pass in
     * @return HTML Object  Return the table populated with User and default settings
     */
    public function buildTableBody($view, $settings)
    {
        // Empty out the body ready to be used.
        $body = '';

        // For each of the settings defined in this Class loop them and build the table.
        foreach (self::$displaySettings as $key => $values) {
            // Check if the title value is set as some options are stand alone options.
            if (isset($values['title'])) {
                $body = $body . '<tr class="heading"><th colspan="2">' . $view->te($values['title']) . '</th></tr>';
            }
            // for each of the options within this group build the rows for them.
            foreach ($values['settings'] as $options) {
                // The user setting value.
                $settingsValues = $settings[$options['id']][$key];
                // Checks for settings to be applied.
                $notificationClass = $settingsValues['disabled'] === 'disabled'
                    ? ' class="notificationDisabled"' : '';
                // Incase option is disabled we set the option to the default value.
                $currentSetting = $settingsValues['disabled'] === 'disabled' ? $settingsValues['default']
                    : $settingsValues['value'];
                // Build the rows and populate with settings.
                $setting =  $options['id'] . '_' . $key;
                $body    = $body
                    . '<tr class="setting">'
                    .     '<td'. $notificationClass . '>'
                    .       '<label for="' . $setting . '">' . $view->te($options['title'])
                    .       '</label>'
                    .     '</td>'
                    .     '<td class="pull-right">'
                    .         '<input id="' . $setting . '" ' . $notificationClass
                    .             'name="' . 'group_notification_settings[' . $setting . ']' . '" type="checkbox" '
                    .             'data-default="' . $settingsValues['default'] . '" '
                    .             $currentSetting . ' ' . $settingsValues['disabled'] . ">"
                    .     '</td>'
                    . '</tr>';
            }
        }
        return  '<tbody>' . $body . '</tbody>';
    }

    /**
     * Given a set of fields in the format role_field = value, convert it into a value which is suitable
     * for storing in the .._notification_settings value of a swarm_group_... key record. It is intended for
     * use by the Group filter defined in the IndexController, but could be reused elsewhere.
     *
     * N.B. It expects roles of the format ..._... such as is_member
     *
     * @param $flatArray and array of the format is_member_new_review
     * @return array of the format {'new_review' => {'is_member'=>bool}}
     */
    public static function buildFromFlatArray($flatArray)
    {
        $settings = [];
        foreach ($flatArray as $settingKey => $settingValue) {
            // Break the key up into 'is', 'role' and $setting
            $actionRole = NotificationSettings::getActionAndRole($settingKey);
            $action     = $actionRole['action'];
            $role       = $actionRole['role'];
            if (!isset($settings[$action])) {
                $settings[$action][$role] =
                    $settingValue === 'on' ? Setting::ENABLED : Setting::DISABLED;
            } else {
                $settings[$action] += [
                    $role => $settingValue === 'on' ? Setting::ENABLED : Setting::DISABLED
                ];
            }
        }
        // Now add in any disabled settings
        foreach (self::$settings as $settingKey => $settingRoleValues) {
            $settingRoles = $settingRoleValues['settings'];
            foreach ($settingRoles as $index => $roleKeyValues) {
                $roleKey = $roleKeyValues['id'];
                if (!isset($settings[$settingKey])) {
                    $settings[$settingKey] = [$roleKey => Setting::DISABLED];
                } elseif (!isset($settings[$settingKey][$roleKey])) {
                    $settings[$settingKey][$roleKey] = Setting::DISABLED;
                }
            }
        }
        return $settings;
    }

    /**
     * Break the key into its action and role.
     * @param $settingKey
     * @return array
     */
    private static function getActionAndRole($settingKey)
    {
        $actionRole = [];
        $parts      = explode('_', $settingKey);
        $size       = sizeof($parts);
        $action     = '';
        // last 2 parts are 'is' and a role like 'member' or 'reviewer'
        for ($i = 0; $i < $size - 2; $i++) {
            if ($action === '') {
                $action = $parts[$i];
            } else {
                $action = $action.'_'.$parts[$i];
            }
        }
        $actionRole['action'] = $action;
        $actionRole['role']   = $parts[$size - 2].'_'.$parts[$size - 1];
        return $actionRole;
    }
}
