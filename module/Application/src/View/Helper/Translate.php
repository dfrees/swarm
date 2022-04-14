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

class Translate extends AbstractTranslatorHelper
{
    public function __invoke(
        $message,
        array $replacements = null,
        $context = null,
        $textDomain = "default",
        $locale = null
    ) {
        if ($this->translator === null) {
            throw new Exception\RuntimeException('Translator has not been set');
        }

        $replacements = array_map(
            [$this->getView()->plugin('escapeHtml')->getEscaper(), 'escapeHtml'],
            (array) $replacements
        );

        return $this->translator->translateReplace($message, $replacements, $context, $textDomain, $locale);
    }
}
