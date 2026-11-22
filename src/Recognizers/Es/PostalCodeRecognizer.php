<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

/**
 * Context-gated: a bare 5-digit number is far too ambiguous, so a postal
 * code is only flagged when introduced as one (CP, C.P., código postal).
 */
class PostalCodeRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'postal_code';
    }

    protected function patterns(): array
    {
        return [
            '/\b(?:C\.?\s?P\.?|[Cc]ódigo\s+[Pp]ostal)\s*[:.\-]?\s*((?:0[1-9]|[1-4]\d|5[0-2])\d{3})\b/u' => 0.9,
        ];
    }
}
