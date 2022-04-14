<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Validator;

use Laminas\Validator\Between;

/**
 * Class Version, validator for review versions.
 * @package Reviews\Validator
 */
class Version extends Between
{
    const FROM_MESSAGE = "Must be an integer between revision [%d] and head [%d] inclusively";
    private $translator;

    /**
     * Constructor.
     * @param mixed         $translator     to translate messages
     * @param array|null    $options        options
     */
    public function __construct($translator, $options)
    {
        $this->translator       = $translator;
        $this->messageTemplates = array_fill_keys(
            [
                self::NOT_BETWEEN,
                self::NOT_BETWEEN_STRICT,
                self::VALUE_NOT_NUMERIC,
                self::VALUE_NOT_STRING,
            ],
            $this->translator->t(self::FROM_MESSAGE, [$options['min'], $options['max']])
        );
        parent::__construct($options);
    }
}
