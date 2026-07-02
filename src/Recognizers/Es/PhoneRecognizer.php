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
        return [
            // Prefixed: unambiguous.
            '/(?<![\d+])(?:\+34|0034)[\s.\-]?\d{3}[\s.\-]?\d{3}[\s.\-]?\d{3}(?!\d)/' => 1.0,
            // Bare mobiles (6xx/7xx).
            '/(?<![\d,.])[67]\d{2}[\s.\-]?\d{3}[\s.\-]?\d{3}(?![\d,.])/' => 0.8,
            // Bare landlines (9xx): more collision-prone with plain numbers.
            '/(?<![\d,.])9[1-8]\d[\s.\-]?\d{3}[\s.\-]?\d{3}(?![\d,.])/' => 0.6,
        ];
    }
}
