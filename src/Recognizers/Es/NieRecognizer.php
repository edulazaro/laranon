<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;

class NieRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'nie';
    }

    protected function patterns(): array
    {
        return [
            '/(?<![\dA-Za-z])[XYZxyz]\s?-?\s?\d{7}\s?-?\s?[A-Za-z](?![\dA-Za-z])/' => 1.0,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::nie($value);
    }
}
