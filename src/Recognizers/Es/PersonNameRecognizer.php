<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\NameRecognizer;

class PersonNameRecognizer extends NameRecognizer
{
    protected function honorificPattern(): ?string
    {
        // D./Dña./Don/Doña introduce a given name (Don Juan, Dña. Luz).
        $word = '\p{Lu}[\p{L}\']*(?:' . self::HYPHEN . '\p{Lu}[\p{L}\']*)*';

        return '/(?:\b(?:Don|Doña)|(?<!\p{L})(?:D|Dña)\.)\s+'
            . '(' . $word . '(?:\s+(?:de(?:\s+l(?:a|os|as))?|del|y|e|la)\s+' . $word . '|\s+' . $word . '){0,3})/u';
    }

    protected function surnameHonorificPattern(): ?string
    {
        // Sr./Sra./Dr./Ldo... introduce a surname (Sr. García, Dra. Vidal).
        $word = '\p{Lu}[\p{L}\']*(?:' . self::HYPHEN . '\p{Lu}[\p{L}\']*)*';

        return '/(?<!\p{L})(?:Srta|Sra|Sr|Dra|Dr|Lcdo|Lcda|Ldo|Lda)\.?\s+'
            . '(' . $word . '(?:\s+(?:de(?:\s+l(?:a|os|as))?|del|y|e|la)\s+' . $word . '|\s+' . $word . '){0,3})/u';
    }

    protected function namePattern(): string
    {
        // "y"/"e" are deliberately NOT connectors here (unlike the honorific
        // pattern): without an explicit title, "Juan Pérez y María López"
        // is far more often two people in a list than one person's compound
        // surname ("Ortega y Gasset"), and merging them into a single span
        // would misattribute PII across two different people.
        $word = '\p{Lu}[\p{Ll}\']+(?:' . self::HYPHEN . '\p{Lu}[\p{Ll}\']+)*';

        return '/(?<![\p{L}\p{N}])' . $word
            . '(?:\s+(?:de(?:\s+l(?:a|os|as))?|del|la)\s+' . $word . '|\s+' . $word . '){1,3}(?![\p{L}\p{N}])/u';
    }

    protected function firstNamesFile(): string
    {
        return 'es/names.php';
    }

    protected function lastNamesFile(): ?string
    {
        return 'es/surnames.php';
    }

    protected function ambiguousNamesFile(): ?string
    {
        return 'es/names_ambiguous.php';
    }
}
