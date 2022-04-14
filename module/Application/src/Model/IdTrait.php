<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Model;

/**
 * Trait IdTrait. Provide common functions for model ids
 * @package Application\Model
 */
trait IdTrait
{
    /**
     * Extends parent to flip the ids ordering and hex encode. Only id values that are integer type or a string
     * representation of an integer will be encoded, all others are returned simply prefixed.
     * Classes that use this trait should provide a KEY_PREFIX const value.
     *
     * @param   string|int  $id     the user facing id
     * @return  string      the stored id used by p4 key
     */
    protected static function encodeId($id)
    {
        // nothing to do if the id is null
        if (!strlen($id)) {
            return null;
        }
        // If this is an integer or it is a string that will translate into an integer perform the encoding
        // for the search. If not leave the id as it is as we do not values encoded into an id which could
        // give incorrect results. For example any string 'abcde' would encode to ffffffff
        if (is_int($id) || ctype_digit($id)) {
            // subtract our id from max 32 bit int value to ensure proper sorting
            // we use a 32 bit value even on 64 bit systems to allow interoperability.
            $id = 0xFFFFFFFF - $id;

            // start with our prefix and follow up with hex encoded id
            // (the higher base makes it slightly shorter)
            $id = str_pad(dechex($id), 8, '0', STR_PAD_LEFT);
        }
        return static::KEY_PREFIX . $id;
    }
}
