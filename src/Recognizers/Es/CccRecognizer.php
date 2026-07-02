<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;

class CccRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'ccc';
    }

    protected function patterns(): array
    {
        return [
            '/(?<!\d)\d{4}[\s\-]?\d{4}[\s\-]?\d{2}[\s\-]?\d{10}(?!\d)/' => 0.95,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::ccc($value);
    }
}
