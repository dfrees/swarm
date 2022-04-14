<?php
/**
 * Partial implementation to help with mulitibyte strings.
 * Based on Symfony\Polyfill\Mbstring.php with only the functionality
 * we need and also allows modification.
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace P4\Validate;

class MultibyteString
{
    private static $encodingList     = array('ASCII', 'UTF-8');
    private static $internalEncoding = 'UTF-8';

    public static function convertEncoding($s, $toEncoding, $fromEncoding = null)
    {
        if (is_array($fromEncoding) || false !== strpos($fromEncoding, ',')) {
            $fromEncoding = self::detectEncoding($s, $fromEncoding);
        } else {
            $fromEncoding = self::getEncoding($fromEncoding);
        }

        $toEncoding = self::getEncoding($toEncoding);

        if ('BASE64' === $fromEncoding) {
            $s            = base64_decode($s);
            $fromEncoding = $toEncoding;
        }

        if ('BASE64' === $toEncoding) {
            return base64_encode($s);
        }

        if ('HTML-ENTITIES' === $toEncoding || 'HTML' === $toEncoding) {
            if ('HTML-ENTITIES' === $fromEncoding || 'HTML' === $fromEncoding) {
                $fromEncoding = 'Windows-1252';
            }
            if ('UTF-8' !== $fromEncoding) {
                $s = iconv($fromEncoding, 'UTF-8//IGNORE', $s);
            }

            return preg_replace_callback('/[\x80-\xFF]+/', array(__CLASS__, 'html_encoding_callback'), $s);
        }

        if ('HTML-ENTITIES' === $fromEncoding) {
            $s            = html_entity_decode($s, ENT_COMPAT, 'UTF-8');
            $fromEncoding = 'UTF-8';
        }
        $result = iconv($fromEncoding, $toEncoding.'//TRANSLIT//IGNORE', $s);

        // Final result to trying to decode the unicode names we have not converted correctly.
        if (!$result) {
            // First get the old locale.
            $oldLocale = setlocale(LC_ALL, 0);
            // Force it into en_us as most system have this installed.
            setlocale(LC_ALL, 'en_US.UTF-8');
            // We must first encode the string to utf8. Then run the iconv again.
            $result = iconv($fromEncoding, $toEncoding.'//TRANSLIT//IGNORE', utf8_encode($s));
            // Return the locale as it was before we issued our command.
            setlocale(LC_ALL, $oldLocale);
        }
        return $result;
    }

    public static function detectEncoding($str, $encodingList = null, $strict = false)
    {
        if (null === $encodingList) {
            $encodingList = self::$encodingList;
        } else {
            if (!is_array($encodingList)) {
                $encodingList = array_map('trim', explode(',', $encodingList));
            }
            $encodingList = array_map('strtoupper', $encodingList);
        }

        foreach ($encodingList as $enc) {
            switch ($enc) {
                case 'ASCII':
                    if (!preg_match('/[\x80-\xFF]/', $str)) {
                        return $enc;
                    }
                    break;

                case 'UTF8':
                case 'UTF-8':
                    if (preg_match('//u', $str)) {
                        return 'UTF-8';
                    }
                    break;

                default:
                    if (0 === strncmp($enc, 'ISO-8859-', 9)) {
                        return $enc;
                    }
            }
        }

        return false;
    }

    private static function getEncoding($encoding)
    {
        if (null === $encoding) {
            return self::$internalEncoding;
        }

        $encoding = strtoupper($encoding);

        if ('8BIT' === $encoding || 'BINARY' === $encoding) {
            return 'CP850';
        }
        if ('UTF8' === $encoding) {
            return 'UTF-8';
        }

        return $encoding;
    }
}
