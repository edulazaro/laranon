<?php

namespace EduLazaro\Laranon\Recognizers\En;

use EduLazaro\Laranon\Contracts\LanguageScoped;
use EduLazaro\Laranon\Recognizers\RegexRecognizer;

/**
 * US ZIP codes: ZIP+4 always (unambiguous format), plain 5-digit only when
 * introduced as a ZIP.
 */
class ZipCodeRecognizer extends RegexRecognizer implements LanguageScoped
{
    public function type(): string
    {
        return 'zip';
    }

    protected function patterns(): array
    {
        return [
            '/(?<!\d)\d{5}-\d{4}(?!\d)/' => 0.9,
            '/\bZIP(?:\s+code)?\s*[:.\-]?\s*(\d{5})\b/i' => 0.9,
        ];
    }
}
