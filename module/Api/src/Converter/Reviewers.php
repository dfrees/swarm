<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Api\Converter;

use Groups\Model\Config;
use Groups\Model\Group;

/**
 * Class to help converting reviewer data between API friendly and application specific structures.
 * @package Api\Converter
 */
class Reviewers
{

    /**
     * Convert users/groups requirement to expand into separate users
     * and groups arrays. For example
     *
     * 'swarm-group-group1' => array('required' => '1')
     * 'user1'              => array()
     *
     * would be converted to
     *
     * 'groups' => array('group1' => array('required' => '1')
     * 'users'  => array('user1'  => array())
     *
     * @param $source array of user or group keys to a requirement detail.
     * @param string $newUserField field for users, defaults to users
     * @param string $newGroupField field for groups, defaults to groups
     * @return array
     */
    public static function expandUsersAndGroups($source, $newUserField = 'users', $newGroupField = 'groups')
    {
        $converted = [];
        if ($source && !empty($source)) {
            foreach ($source as $name => $detail) {
                // Users and groups may also be numbers
                $stringName = (string)$name;
                if (Group::isGroupName($stringName) === true) {
                    $converted[$newGroupField][Group::getGroupName($stringName)] = $detail;
                } else {
                    $converted[$newUserField][$stringName] = is_array($detail) ? $detail : [];
                }
            }
        }
        return $converted;
    }

    /**
     * Convert users/groups requirement to collapse single array. For example
     *
     * 'groups' => array('group1' => array('required' => '1')
     * 'users'  => array('user1'  => array())
     *
     * would be converted to
     *
     * 'swarm-group-group1' => array('required' => '1')
     * 'user1'              => array()
     *
     * @param $source array of user or group keys to a requirement detail.
     * @param string $newUserField field for users, defaults to users
     * @param string $newGroupField field for groups, defaults to groups
     * @return array
     */
    public static function collapseUsersAndGroups($source, $newUserField = 'users', $newGroupField = 'groups')
    {
        $converted = [];
        if ($source && !empty($source)) {
            if (isset($source[$newUserField])) {
                foreach ($source[$newUserField] as $user => $detail) {
                    $converted[$user] = $detail;
                }
            }
            if (isset($source[$newGroupField])) {
                foreach ($source[$newGroupField] as $group => $detail) {
                    $converted[Config::KEY_PREFIX . $group] = $detail;
                }
            }
        }
        return empty($converted) ? $source : $converted;
    }
}
