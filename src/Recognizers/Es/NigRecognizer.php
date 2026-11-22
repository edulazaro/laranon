<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

/**
 * Court case references: the 19-digit NIG and procedure numbers such as
 * "autos 123/2023". Both are context-gated to avoid eating plain fractions.
 */
class NigRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'case_ref';
    }

    protected function patterns(): array
    {
        return [
            '/\bNIG\b\s*[:.\-]?\s*(\d[\d\s\-.]{15,25}\d)/u' => 0.95,
            '/\b(?:autos|expediente|procedimiento|proc\.|ejecuci[oó]n|recurso|sumario|diligencias)\s+(?:n[ºo°]?\.?\s*|n[uú]mero\s+)?(\d{1,6}\/(?:19|20)\d{2})\b/iu' => 0.9,
        ];
    }
}
