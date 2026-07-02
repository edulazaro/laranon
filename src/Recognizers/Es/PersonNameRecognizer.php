<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\NameRecognizer;

class PersonNameRecognizer extends NameRecognizer
{
    protected function honorificPattern(): ?string
    {
        // D./Dña./Don/Doña introduce a given name (Don Juan, Dña. Luz).
        return '/(?:\b(?:Don|Doña)|(?<!\p{L})(?:D|Dña)\.)\s+'
            . '(\p{Lu}[\p{L}\']*(?:\s+(?:de(?:\s+l(?:a|os|as))?|del|y|e|la)\s+\p{Lu}[\p{L}\']*|\s+\p{Lu}[\p{L}\']*){0,3})/u';
    }

    protected function surnameHonorificPattern(): ?string
    {
        // Sr./Sra./Dr./Ldo... introduce a surname (Sr. García, Dra. Vidal).
        return '/(?<!\p{L})(?:Srta|Sra|Sr|Dra|Dr|Lcdo|Lcda|Ldo|Lda)\.?\s+'
            . '(\p{Lu}[\p{L}\']*(?:\s+(?:de(?:\s+l(?:a|os|as))?|del|y|e|la)\s+\p{Lu}[\p{L}\']*|\s+\p{Lu}[\p{L}\']*){0,3})/u';
    }

    protected function namePattern(): string
    {
        // "y"/"e" are deliberately NOT connectors here (unlike the honorific
        // pattern): without an explicit title, "Juan Pérez y María López"
        // is far more often two people in a list than one person's compound
        // surname ("Ortega y Gasset"), and merging them into a single span
        // would misattribute PII across two different people.
        return '/(?<![\p{L}\p{N}])\p{Lu}[\p{Ll}\']+'
            . '(?:\s+(?:de(?:\s+l(?:a|os|as))?|del|la)\s+\p{Lu}[\p{Ll}\']+|\s+\p{Lu}[\p{Ll}\']+){1,3}(?![\p{L}\p{N}])/u';
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
