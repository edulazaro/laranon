<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;

class NssRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'nss';
    }

    protected function patterns(): array
    {
        return [
            '/(?<!\d)\d{2}[\s\/\-]?\d{8}[\s\/\-]?\d{2}(?!\d)/' => 0.9,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::nss($value);
    }
}
