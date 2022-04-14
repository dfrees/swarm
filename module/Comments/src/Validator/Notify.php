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

/**
 * Class Notify. Validates that a value provided for 'notify' is one of the accepted values
 * @package Comments\Validator
 */
class Notify extends ArrayValuesValidator
{
    const NOTIFY_FIELD   = 'notify';
    const DELAYED        = 'delayed';
    const IMMEDIATE      = 'immediate';
    const SILENT         = 'silent';
    const VALID_NOTIFY   = [self::DELAYED, self::IMMEDIATE, self::SILENT];
    const INVALID_NOTIFY = 'invalidNotify';

    /**
     * Constructor.
     * @param mixed     $translator     translator to translate strings
     */
    public function __construct($translator)
    {
        parent::__construct(
            $translator,
            self::VALID_NOTIFY,
            self::INVALID_NOTIFY,
            self::NOTIFY_FIELD,
            [
                self::CASE_SENSITIVE => true,
            ]
        );
    }
}
