<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Comments\Validator;

use Application\Validator\ArrayValuesValidator;
use Comments\Model\Comment;

/**
 * Class TaskState. Validates that a taskState value is one of the accepted values
 * @package Comments\Validator
 */
class TaskState extends ArrayValuesValidator
{
    const COMMENT            = 'comment';
    const OPEN               = 'open';
    const ADDRESSED          = 'addressed';
    const VERIFIED           = 'verified';
    const VERIFIED_ARCHIVE   = 'verified:archive';
    const VALID_TASK_STATES  = [self::COMMENT, self::OPEN, self::ADDRESSED, self::VERIFIED, self::VERIFIED_ARCHIVE];
    const INVALID_TASK_STATE = 'invalidTaskState';

    /**
     * Constructor.
     * @param mixed     $translator     translator to translate strings
     */
    public function __construct($translator, $options = [])
    {
        parent::__construct(
            $translator,
            self::VALID_TASK_STATES,
            self::INVALID_TASK_STATE,
            Comment::FETCH_BY_TASK_STATE,
            [
                self::CASE_SENSITIVE => true,
                self::SUPPORT_ARRAYS => $options[self::SUPPORT_ARRAYS]??true,
            ]
        );
    }
}
