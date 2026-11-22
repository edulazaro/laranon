<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;
use EduLazaro\Laranon\Support\Separators;

class NssRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'nss';
    }

    protected function patterns(): array
    {
        return [
            '/(?<!\d)\d{2}(?:' . Separators::ONE . '|\/)?\d{8}(?:' . Separators::ONE . '|\/)?\d{2}(?!\d)/' => 0.9,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::nss($value);
    }
}
