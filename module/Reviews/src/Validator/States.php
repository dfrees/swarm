<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Validator;

use Application\Validator\ArrayValuesValidator;
use Reviews\ITransition;
use Reviews\Model\IReview;

class States extends ArrayValuesValidator implements ITransition
{
    const VALID_STATES  = 'valid_states';
    const INVALID_STATE = 'invalidState';

    /**
     * Constructor.
     *
     * @param mixed     $translator     translator to translate strings
     * @param array     $options        options to configure the validator.
     *                                  Accepts 'valid_states' => [array of valid states]. Valid states default to
     *                                  ITransition::ALL_VALID_TRANSITIONS if not provided
     */
    public function __construct($translator, $options = [])
    {
        $validTransitions = self::ALL_VALID_TRANSITIONS;
        if ($options && isset($options[self::VALID_STATES])) {
            $validTransitions = $options[self::VALID_STATES];
        }
        parent::__construct(
            $translator,
            $validTransitions,
            self::INVALID_STATE,
            IReview::FETCH_BY_STATE,
            [
                self::CASE_SENSITIVE => true,
                self::SUPPORT_ARRAYS => true
            ]
        );
    }
}
