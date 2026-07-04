<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;

class DniRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'dni';
    }

    protected function patterns(): array
    {
        return [
            // 8 digits (optionally dotted as thousands, "12.345.678"), then the
            // control letter. The checksum validates after stripping separators.
            '/(?<![\dA-Za-z])(?:\d{2}\.\d{3}\.\d{3}|\d{8})\s?-?\s?[A-Za-z](?![\dA-Za-z])/' => 1.0,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::dni($value);
    }
}
