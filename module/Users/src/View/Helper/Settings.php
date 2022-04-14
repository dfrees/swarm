<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Users\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Renderer\RendererInterface as Renderer;
use Users\Settings\ReviewPreferences;
use Users\Settings\TimePreferences;
use Application\Config\ConfigManager;

class Settings extends AbstractHelper
{
    const TITLE = "title";
    const ID    = "id";

    const TIME_TITLE       = 'Time Display';
    const TIME_DESCRIPTION = 'Sets how you would like to display time across Swarm.';

    /**
     * This is not ideal. In order for values to be picked up for .po generation strings
     * must be specifically mentioned with a call to a function that returns a string
     * (even though it will actually never be called).
     * We are forced to repeat the values for consts above.
     */
    private static function msgIds()
    {
        self::t('Show comments in files');
        self::t('View diffs side-by-side');
        self::t('Show space and newline characters');
        self::t('Ignore whitespace when calculating differences');
        self::t('Time Display');
        self::t('Sets how you would like to display time across Swarm.');
        self::t('Display the time in');
        self::t('Timeago');
        self::t('Timestamp');
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

    public static $userReviewPreferences = [
        ReviewPreferences::SHOW_COMMENTS_IN_FILES,
        ReviewPreferences::VIEW_DIFFS_SIDE_BY_SIDE,
        ReviewPreferences::SHOW_SPACE_AND_NEW_LINE,
        ReviewPreferences::IGNORE_WHITESPACE,
    ];

    public static $userTimePreferences = [
        TimePreferences::DISPLAY,
    ];

    public static $userReviewSettingsDisplay = [
        [
            self::ID    => ReviewPreferences::SHOW_COMMENTS_IN_FILES,
            self::TITLE => ReviewPreferences::SHOW_COMMENTS_IN_FILES_TEXT,
        ],
        [
            self::ID    => ReviewPreferences::VIEW_DIFFS_SIDE_BY_SIDE,
            self::TITLE => ReviewPreferences::VIEW_DIFFS_SIDE_BY_SIDE_TEXT,
        ],
        [
            self::ID    => ReviewPreferences::SHOW_SPACE_AND_NEW_LINE,
            self::TITLE => ReviewPreferences::SHOW_SPACE_AND_NEW_LINE_TEXT,
        ],
        [
            self::ID    => ReviewPreferences::IGNORE_WHITESPACE,
            self::TITLE => ReviewPreferences::IGNORE_WHITESPACE_TEXT,
        ]
    ];

    public static $userTimeSettingsDisplay = [
        [
            self::ID    => TimePreferences::DISPLAY,
            self::TITLE => TimePreferences::DISPLAY_TIME_IN_TIMESTAMP_OR_TIMEAGO,
        ]
    ];

    /**
     * Provides a table of setting for the user page current viewing.
     *
     * @param $settings     // get the settings passed in.
     * @return string Object  // return back the HTML object of the table
     */
    public function __invoke($settings)
    {
        $view = $this->getView();
        // Build the review table first.
        $reviewTable =  '<table id="reviewSettingsTable" class="table table-hover table-striped">'
            . $this->buildReviewTableBody($this->getView(), $settings). '</table>';
        // Now build the time header and table.
        $timeHeader = '<div><h4>'.$view->te(self::TIME_TITLE).'</h4><p>'.$view->te(self::TIME_DESCRIPTION).'</p></div>';
        $timeTable  = $timeHeader.'<table id="timeSettingsTable" class="table table-hover table-striped">'
            . $this->buildTimeTableBody($view, $settings). '</table>';
        // Return both tables back.
        return $reviewTable . $timeTable;
    }

    /**
     * Build the review table body based on user settings otherwise use default settings
     *
     * @param $view         Renderer allow us to use the translate on the title we require the view.
     * @param $settings     array the users and default settings are pass in
     * @return string Object  Return the table populated with User and default settings
     */
    public function buildReviewTableBody($view, $settings)
    {
        // Empty out the body ready to be used.
        $body     = '';
        $class    = 'level1';
        $rowCount = 0;
        foreach (self::$userReviewSettingsDisplay as $display) {
            $settingsValue =
                $settings[ConfigManager::SETTINGS][ReviewPreferences::REVIEW_PREFERENCES][$display['id']];

            $body = $body
                . '<tr class="'.$class. ($rowCount === 0 ? ' toprow' : '') . '">'
                .'<td>'
                .  $view->te($display['title'])
                . '</td>'
                . '<td class="pull-right">'
                . '<input id="' . $display['id'] . '" '
                . 'name="' .$display['id'] . '" type="checkbox" data-default="'
                . $settingsValue['default'] . '" ' . $settingsValue['value'] . ' '
                . ">"
                . '</td>'
                . '</tr>';
            $rowCount++;
        }
        return  '<tbody>' . $body . '</tbody>';
    }

    public function buildTimeTableBody($view, $settings)
    {
        // Empty out the body ready to be used.
        $body      = '';
        $bodyClass = '';
        $class     = 'level1';
        $rowCount  = 0;

        $timeDisplayDefaults = [TimePreferences::TIMEAGO, TimePreferences::TIMESTAMP];

        foreach (self::$userTimeSettingsDisplay as $display) {
            $settingsValue =
                $settings[ConfigManager::SETTINGS][TimePreferences::TIME_PREFERENCES][$display['id']];

            $body = $body
                . '<tr class="'.$class. ($rowCount === 0 ? ' toprow' : '') . ' '.strtolower($display['id']). '">'
                .'<td>'
                .  $view->te($display['title'])
                . '</td>'
                . '<td class="pull-right">';

            if (strcasecmp($display['id'], TimePreferences::DISPLAY) === 0) {
                $forEachSection = null;
                $forEachSection = $timeDisplayDefaults;
                $bodyClass      = strtolower($settingsValue['value']);

                $body = $body . '<select id="time-format-'.$display['id'].'" name="'.$display['id'].'" data-default="'
                    .$settingsValue['default'] . '">';
                foreach ($forEachSection as $value) {
                    $selected = "";
                    if (isset($settingsValue['value']) && strcasecmp($settingsValue['value'], $value) == 0) {
                        $selected = "selected";
                    }
                    $body = $body . '<option id="'.$value.'" value="'.$value.'" '.$selected.'>'.$view->te($value)
                        .'</option>';
                }
                $body = $body . '</select>';
            }
                $body = $body. '</td>'
                . '</tr>';
            $rowCount++;
        }
        return  '<tbody class="'.$bodyClass.'">' . $body . '</tbody>';
    }
}
