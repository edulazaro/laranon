<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Separators;

class PhoneRecognizer extends RegexRecognizer
{
    /**
     * Not followed by a currency or a percent: "600.000.000 euros" is an amount.
     * UTF-8 bytes, as the pattern runs without the u modifier.
     */
    protected const NOT_AN_AMOUNT = '(?!' . Separators::BLANK . '*(?i:\xE2\x82\xAC|%|\$|euros?\b|eur\b|ptas?\b|pesetas\b|d(?:o|\xC3\xB3)lares\b|usd\b))';

    public function type(): string
    {
        return 'phone';
    }

    protected function patterns(): array
    {
        // Country-prefixed numbers (+34, 0034...) are handled by the universal
        // PhoneRecognizer. Here: BARE national numbers, 9 digits, in any grouping
        // whose first group has two digits or more (with or without
        // spaces/dots/hyphens): "600123123", "600 123 123", "600 12 31 23",
        // "600-12-31-23", "91 123 45 67". A trailing '.'/',' is allowed (end of
        // sentence) as long as it is not a decimal separator before more
        // digits, and a currency or a percent after it makes it an amount
        // ("600.000.000 euros").
        $digits = '(?:(?:' . Separators::ONE . '|\.)?\d){7}(?!\d)(?![.,]\d)' . self::NOT_AN_AMOUNT;

        return [
            // Bare mobiles (6xx/7xx).
            '/(?<![\d+.])[67]\d' . $digits . '/' => 0.8,
            // Bare landlines (9[1-8]x): more collision-prone with plain numbers.
            '/(?<![\d+.])9[1-8]' . $digits . '/' => 0.6,
        ];
    }
}
