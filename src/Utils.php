<?php
/**
 * Created by PhpStorm.
 * User: Andrey Mistulov
 * Company: Aristos
 * Email: a.mistulov@aristos.pw
 * Date: 21.02.2018 14:18
 */

namespace Prowebcraft\Telebot;


class Utils
{

    /**
     * Clean string for use as identifier
     * @param $identifier
     * @param array $filter
     * @return mixed|null|string|string[]
     */
    public static function cleanIdentifier($identifier, array $filter = array(
        ' ' => '-',
        '_' => '-',
        '/' => '-',
        '[' => '-',
        ']' => '',
    ))
    {
        $identifier = str_replace(array_keys($filter), array_values($filter), $identifier);

        // Valid characters are:
        // - the hyphen (U+002D)
        // - a-z (U+0030 - U+0039)
        // - A-Z (U+0041 - U+005A)
        // - the underscore (U+005F)
        // - 0-9 (U+0061 - U+007A)
        // - ISO 10646 characters U+00A1 and higher
        // We strip out any character not in the above list.
        $identifier = preg_replace('/[^\\x{002D}\\x{0030}-\\x{0039}\\x{0041}-\\x{005A}\\x{005F}\\x{0061}-\\x{007A}\\x{00A1}-\\x{FFFF}]/u', '', $identifier);

        // Identifiers cannot start with a digit, two hyphens, or a hyphen followed by a digit.
        $identifier = preg_replace(array(
            '/^[0-9]/',
            '/^(-[0-9])|^(--)/',
        ), array(
            '_',
            '__',
        ), $identifier);
        $identifier = strtolower($identifier);

        return $identifier;
    }
}
