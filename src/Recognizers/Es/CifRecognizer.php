<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;

class CifRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'cif';
    }

    protected function patterns(): array
    {
        return [
            '/(?<![\dA-Za-z])[ABCDEFGHJKLMNPQRSUVW]\s?-?\s?\d{7}\s?-?\s?[0-9A-Ja-j](?![\dA-Za-z])/' => 1.0,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::cif($value);
    }
}
