<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

use Laminas\I18n\Exception;
use Laminas\I18n\View\Helper\AbstractTranslatorHelper;

class TranslatePluralEscape extends AbstractTranslatorHelper
{
    public function __invoke(
        $singular,
        $plural,
        $number,
        array $replacements = null,
        $context = null,
        $textDomain = 'default',
        $locale = null
    ) {
        if ($this->translator === null) {
            throw new Exception\RuntimeException('Translator has not been set');
        }

        return $this->translator->translatePluralReplaceEscape(
            $singular,
            $plural,
            $number,
            $replacements,
            $context,
            $textDomain,
            $locale
        );
    }
}
