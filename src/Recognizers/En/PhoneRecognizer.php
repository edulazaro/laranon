<?php

namespace EduLazaro\Laranon\Recognizers\En;

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
            // US: +1 or (area) formats, separators required.
            '/(?<![\d+])(?:\+1[\s.\-]?)?\(\d{3}\)[\s.\-]?\d{3}[\s.\-]?\d{4}(?!\d)/' => 0.95,
            '/(?<![\d+])\+1[\s.\-]?\d{3}[\s.\-]?\d{3}[\s.\-]?\d{4}(?!\d)/' => 0.95,
            '/(?<![\d+.])\d{3}[.\-]\d{3}[.\-]\d{4}(?![\d.])/' => 0.8,
            // UK: +44 prefixed.
            '/(?<![\d+])\+44\s?\d{4}\s?\d{6}(?!\d)/' => 0.95,
        ];
    }
}
