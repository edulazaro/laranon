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
            // The 7 digits bare or grouped by dots with the control glued to
            // them ("B-12.345.674").
            '/(?<![\dA-Za-z])[ABCDEFGHJKLMNPQRSUVW]' . Separators::GAP . '(?:\d{7}' . Separators::GAP . '|\d{2}\.\d{3}\.\d{2})[0-9A-Ja-j](?![\dA-Za-z])/' => 1.0,
        ];
    }

    protected function validate(string $value): bool
    {
        return Checksums::cif($value);
    }
}
