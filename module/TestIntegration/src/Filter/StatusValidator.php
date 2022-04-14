<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Filter;

use Application\Validator\ArrayValuesValidator;

/**
 * Class StatusValidator to validate the test run status
 * @package TestIntegration\Filter
 */
class StatusValidator extends ArrayValuesValidator
{
    // Statuses
    const STATUS_PASS        = 'pass';
    const STATUS_FAIL        = 'fail';
    const STATUS_RUNNING     = 'running';
    const STATUS_NOT_STARTED = 'notstarted';
    const VALID_STATUSES     = [self::STATUS_PASS, self::STATUS_FAIL, self::STATUS_RUNNING, self::STATUS_NOT_STARTED];
    const INVALID_STATUS     = 'invalidStatus';

    /**
     * StatusValidator constructor.
     * @param mixed     $translator     to translate messages
     */
    public function __construct($translator)
    {
        parent::__construct($translator, self::VALID_STATUSES, self::INVALID_STATUS, 'status');
    }
}
