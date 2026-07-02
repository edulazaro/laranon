<?php

namespace EduLazaro\Laranon\Recognizers\En;

use EduLazaro\Laranon\Recognizers\NameRecognizer;

class PersonNameRecognizer extends NameRecognizer
{
    protected function honorificPattern(): ?string
    {
        // Sir/Lady conventionally precede a given name (Sir Ian, Lady Diana).
        return '/\b(?:Sir|Lady)\s+(\p{Lu}[\p{Ll}\']+(?:\s+\p{Lu}[\p{Ll}\']+){0,3})/u';
    }

    protected function surnameHonorificPattern(): ?string
    {
        // Mr/Mrs/Ms/Dr/Prof conventionally precede a surname (Mr. Smith).
        return '/\b(?:Mr|Mrs|Ms|Miss|Dr|Prof)\.?\s+'
            . '(\p{Lu}[\p{Ll}\']+(?:\s+\p{Lu}[\p{Ll}\']+){0,3})/u';
    }

    protected function namePattern(): string
    {
        return '/(?<![\p{L}\p{N}])\p{Lu}[\p{Ll}\']+(?:\s+\p{Lu}[\p{Ll}\']+){1,3}(?![\p{L}\p{N}])/u';
    }

    protected function firstNamesFile(): string
    {
        return 'en/names.php';
    }

    protected function lastNamesFile(): ?string
    {
        return 'en/surnames.php';
    }

    protected function ambiguousNamesFile(): ?string
    {
        return 'en/names_ambiguous.php';
    }
}
