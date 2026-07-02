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
            '/(?<![\dA-Za-z])\d{8}\s?-?\s?[A-Za-z](?![\dA-Za-z])/' => 1.0,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::dni($value);
    }
}
