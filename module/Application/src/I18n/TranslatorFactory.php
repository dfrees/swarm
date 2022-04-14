<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\I18n;

use Application\Config\ConfigManager;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class TranslatorFactory implements FactoryInterface
{
    const SERVICE = 'translator';

     /**
     * Builds an instance of requestedName passing it services and options (if provided).
     * @param ContainerInterface        $services       application services
     * @param string                    $requestedName  class name to construct, must implement InvokableService
     * @param array|null                $options        options
     * @return object|\Laminas\Mvc\I18n\Translator
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config        = $services->get(ConfigManager::CONFIG);
        $config        = $config['translator'] ?? $config['translator'];
        $translator    = Translator::factory($config);
        $mvcTranslator = new \Laminas\Mvc\I18n\Translator($translator);

        $translator->setEscaper(new \Application\Escaper\Escaper);

        // add event listener for context fallback on missing translations
        $translator->enableEventManager();
        $translator->getEventManager()->attach(
            $translator::EVENT_MISSING_TRANSLATION,
            [$translator, 'handleMissingTranslation']
        );

        // establish default locale settings
        $translator->setLocale($translator->getLocale() ?: 'en_US');
        $translator->setFallbackLocale($translator->getFallbackLocale() ?: 'en_US');

        // try to guess locale from browser language header (using intl if available)
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            && (!isset($config['detect_locale']) || $config['detect_locale'] !== false)
        ) {
            $locale = extension_loaded('intl')
                ? \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE'])
                : str_replace('-', '_', current(explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])));

            // if we can't find an exact match, venture a guess based on language prefix
            if (!$translator->isSupportedLocale($locale)) {
                $language = current(preg_split('/[^a-z]/i', $locale));
                $locale   = $translator->isSupportedLanguage($language) ?: $locale;
            }

            $translator->setLocale(strlen($locale) ? $locale : $translator->getLocale());
        }

        return $mvcTranslator;
    }
}
