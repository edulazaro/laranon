<?php

namespace EduLazaro\Laranon\Recognizers\En;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

/**
 * Context-gated: passport numbers have no universal format, so they are only
 * flagged when introduced as such ("passport no. X1234567").
 */
class PassportRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'passport';
    }

    protected function patterns(): array
    {
        return [
            '/\bpassport\s*(?:no\.?|number|#)?\s*[:\-]?\s*([A-Z0-9]{6,9})\b/i' => 0.9,
            '/\bpasaporte\s*(?:n[ºo°]?\.?|número)?\s*[:\-]?\s*([A-Z0-9]{6,9})\b/iu' => 0.9,
        ];
    }
}
