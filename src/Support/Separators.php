<?php

namespace EduLazaro\Laranon\Support;

/**
 * Separator fragments shared by the recognizer patterns, so an identifier is
 * found with the spaces and dashes that word processors and PDFs put between
 * its groups, not only with the ASCII ones.
 *
 * They are UTF-8 byte sequences on purpose: the patterns run without the u
 * modifier, which in PHP would also widen \d, \w and \b to Unicode and change
 * what every existing pattern matches.
 */
final class Separators
{
    /**
     * ASCII whitespace plus the no-break space (U+00A0) and the narrow
     * no-break space (U+202F).
     */
    public const SPACE = '(?:\s|\xC2\xA0|\xE2\x80\xAF)';

    /**
     * The ASCII hyphen plus the typographic dashes (U+2010 to U+2015) and the
     * minus sign (U+2212).
     */
    public const DASH = '(?:-|\xE2\x80[\x90-\x95]|\xE2\x88\x92)';

    /**
     * One space or one dash.
     */
    public const ONE = '(?:' . self::SPACE . '|' . self::DASH . ')';

    /**
     * An optional space, an optional dash and an optional space: "12345678 - Z".
     */
    public const GAP = self::SPACE . '?' . self::DASH . '?' . self::SPACE . '?';

    /**
     * Removes every space and dash, plus the extra characters given, so a
     * checksum sees the bare identifier.
     */
    public static function strip(string $value, string $also = ''): string
    {
        $pattern = '/' . self::SPACE . '|' . self::DASH . ($also === '' ? '' : '|[' . preg_quote($also, '/') . ']') . '/';

        return (string) preg_replace($pattern, '', $value);
    }
}
