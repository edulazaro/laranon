<?php

namespace EduLazaro\Laranon\Recognizers\Universal;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

/**
 * International, country-prefixed phone numbers: a leading '+' or '00' followed
 * by a country code (1-3 digits) and the national number, with or without
 * separators (spaces, dots, hyphens). Locale-agnostic, so '+34', '+376', '+44',
 * '+33', '+1'... are all caught regardless of the active packs. Bare national
 * numbers (no prefix) stay with the locale recognizers, which know each
 * country's number ranges. A bare prefix with no number ('+34') never matches.
 */
class PhoneRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'phone';
    }

    protected function patterns(): array
    {
        return [
            // '+' or '00', a 1-3 digit country code, then 6-13 more national
            // digits with optional separators between any of them. Not preceded
            // by a word char or '+' (so it starts on a real boundary), and not
            // followed by another digit.
            '/(?<![\w+])(?:\+|00)[\s.\-]?\d{1,3}(?:[\s.\-]?\d){6,13}(?!\d)/' => 1.0,
        ];
    }
}
