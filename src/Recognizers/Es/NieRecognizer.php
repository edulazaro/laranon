<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;
use EduLazaro\Laranon\Support\Separators;

class NieRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'nie';
    }

    protected function patterns(): array
    {
        return [
            // X/Y/Z, 7 digits (optionally dotted, "1.234.567"), control letter.
            '/(?<![\dA-Za-z])[XYZxyz]' . Separators::GAP . '(?:\d\.\d{3}\.\d{3}|\d{7})' . Separators::GAP . '[A-Za-z](?![\dA-Za-z])/' => 1.0,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::nie($value);
    }
}
