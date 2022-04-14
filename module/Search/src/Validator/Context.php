<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Search\Validator;

use Application\Validator\ArrayValuesValidator;
use Search\Filter\ISearch;

/**
 * Validator to validate the contexts for the search API
 * @package Search\Validator
 */
class Context extends ArrayValuesValidator
{
    const INVALID_CONTEXT = 'invalidContext';
    const USER            = 'user';
    const GROUP           = 'group';
    const PROJECT         = 'project';
    const FILE_PATH       = 'filePath';
    const FILE_CONTENT    = 'fileContent';
    const VALID_CONTEXTS  = [self::USER, self::GROUP, self::PROJECT, self::FILE_PATH, self::FILE_CONTENT];

    /**
     * Context constructor.
     * @param $translator
     * @param array $options
     */
    public function __construct($translator, array $options = [])
    {
        parent::__construct(
            $translator,
            self::VALID_CONTEXTS,
            self::INVALID_CONTEXT,
            ISearch::CONTEXT,
            [
                self::CASE_SENSITIVE => true,
                self::SUPPORT_ARRAYS => true
            ]
        );
    }
}
