<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

class PhoneRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'phone';
    }

    protected function patterns(): array
    {
        // Country-prefixed numbers (+34, 0034...) are handled by the universal
        // PhoneRecognizer. Here: BARE national numbers, 9 digits, in any grouping
        // (with or without spaces/dots/hyphens): "600123123", "600 123 123",
        // "600 12 31 23", "600-12-31-23". A trailing '.'/',' is allowed (end of
        // sentence) as long as it is not a decimal separator before more digits.
        return [
            // Bare mobiles (6xx/7xx).
            '/(?<![\d+.])[67]\d{2}(?:[\s.\-]?\d){6}(?!\d)(?![.,]\d)/' => 0.8,
            // Bare landlines (9[1-8]x): more collision-prone with plain numbers.
            '/(?<![\d+.])9[1-8]\d(?:[\s.\-]?\d){6}(?!\d)(?![.,]\d)/' => 0.6,
        ];
    }
}
