<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Separators;

/**
 * Modern Spanish plates: 4 digits + 3 consonants (no vowels, no Q/Ñ).
 */
class PlateRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'plate';
    }

    protected function patterns(): array
    {
        return [
            '/(?<![\dA-Za-z])\d{4}' . Separators::GAP . '[BCDFGHJKLMNPRSTVWXYZ]{3}(?![\dA-Za-z])/' => 0.85,
        ];
    }
}
