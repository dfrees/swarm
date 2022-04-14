<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\Filter;

use Application\Validator\ArrayValuesValidator;

/**
 * Class ChangeActionValidator. Validates actions that can be applied to a change
 * @package Files\Filter
 */
class ChangeActionValidator extends ArrayValuesValidator
{
    const INVALID_ACTION = 'invalidAction';

    /**
     * ChangeActionValidator constructor.
     * @param mixed     $translator     to translate messages
     */
    public function __construct($translator)
    {
        parent::__construct($translator, IFile::VALID_ACTIONS, self::INVALID_ACTION, IFile::ACTION);
    }
}
