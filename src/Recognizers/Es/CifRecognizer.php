<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;
use EduLazaro\Laranon\Support\Separators;

class CifRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'cif';
    }

    protected function patterns(): array
    {
        return [
            '/(?<![\dA-Za-z])[ABCDEFGHJKLMNPQRSUVW]' . Separators::GAP . '\d{7}' . Separators::GAP . '[0-9A-Ja-j](?![\dA-Za-z])/' => 1.0,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::cif($value);
    }
}
