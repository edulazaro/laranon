<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;
use EduLazaro\Laranon\Support\Separators;

class CccRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'ccc';
    }

    protected function patterns(): array
    {
        return [
            '/(?<!\d)\d{4}' . Separators::ONE . '?\d{4}' . Separators::ONE . '?\d{2}' . Separators::ONE . '?\d{10}(?!\d)/' => 0.95,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::ccc($value);
    }
}
