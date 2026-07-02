<?php

namespace EduLazaro\Laranon\Recognizers\En;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

/**
 * US Social Security Numbers. Separators are required (123-45-6789):
 * a bare 9-digit run is far too ambiguous. Known-invalid ranges excluded.
 */
class SsnRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'ssn';
    }

    protected function patterns(): array
    {
        return [
            '/(?<![\d\-])(?!000|666|9\d{2})\d{3}[\- ](?!00)\d{2}[\- ](?!0000)\d{4}(?![\d\-])/' => 0.95,
        ];
    }
}
