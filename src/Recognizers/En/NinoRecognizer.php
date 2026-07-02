<?php

namespace EduLazaro\Laranon\Recognizers\En;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

/**
 * UK National Insurance Numbers (AB123456C), excluding the prefixes HMRC
 * never issues.
 */
class NinoRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'nino';
    }

    protected function patterns(): array
    {
        return [
            '/\b(?!BG|GB|NK|KN|TN|NT|ZZ)[ABCEGHJ-PRSTW-Z][ABCEGHJ-NPRSTW-Z]\s?\d{2}\s?\d{2}\s?\d{2}\s?[A-D]\b/' => 0.9,
        ];
    }
}
