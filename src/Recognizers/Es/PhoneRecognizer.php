<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Contracts\LanguageScoped;
use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Separators;

class PhoneRecognizer extends RegexRecognizer implements LanguageScoped
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
        // PhoneRecognizer. Here: BARE national numbers, 9 digits, grouped as
        // the numbering plan writes them (with or without spaces/dots/hyphens):
        // a mobile from its first three digits ("600123123", "600 123 123",
        // "600 12 31 23", "600-12-31-23"), a landline from its area code, which
        // has two digits in Madrid and Barcelona ("91 123 45 67") and three
        // elsewhere ("985 12 34 56"). A trailing '.'/',' is allowed (end of
        // sentence) as long as it is not a decimal separator before more
        // digits, and a currency or a percent after it makes it an amount
        // ("600.000.000 euros").
        $separator = '(?:' . Separators::ONE . '|\.)';
        $end = '(?!\d)(?![.,]\d)' . self::NOT_AN_AMOUNT;

        return [
            // Bare mobiles (6xx/7xx).
            '/(?<![\d+.])[67]\d{2}(?:' . $separator . '?\d){6}' . $end . '/' => 0.8,
            // Bare landlines (9[1-8]x, or 91/93 then a separator): more
            // collision-prone with plain numbers.
            '/(?<![\d+.])(?:9[1-8]\d|9[13]' . $separator . '\d)(?:' . $separator . '?\d){6}' . $end . '/' => 0.6,
        ];
    }
}
