<?php

namespace EduLazaro\Laranon\Recognizers\Universal;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;

class IbanRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'iban';
    }

    protected function patterns(): array
    {
        return [
            '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{2,4}){3,8}\b/' => 1.0,
        ];
    }

    protected function validate(string $value): bool
    {
        $normalized = preg_replace('/\s/', '', $value);

        return strlen($normalized) >= 15
            && strlen($normalized) <= 34
            && Checksums::iban($normalized);
    }
}
