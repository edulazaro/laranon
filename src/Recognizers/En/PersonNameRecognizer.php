<?php

namespace EduLazaro\Laranon\Recognizers\En;

use EduLazaro\Laranon\Recognizers\NameRecognizer;

class PersonNameRecognizer extends NameRecognizer
{
    protected function honorificPattern(): ?string
    {
        // Sir/Lady conventionally precede a given name (Sir Ian, Lady Diana).
        return '/\b(?:Sir|Lady)\s+(' . $this->word() . '(?:\s+' . $this->word() . '){0,3})/u';
    }

    protected function surnameHonorificPattern(): ?string
    {
        // Mr/Mrs/Ms/Dr/Prof conventionally precede a surname (Mr. Smith).
        return '/\b(?:Mr|Mrs|Ms|Miss|Dr|Prof)\.?\s+'
            . '(' . $this->word() . '(?:\s+' . $this->word() . '){0,3})/u';
    }

    protected function namePattern(): string
    {
        return '/(?<![\p{L}\p{N}])' . $this->word() . '(?:\s+' . $this->word() . '){1,3}(?![\p{L}\p{N}])/u';
    }

    /**
     * A capitalized name word (see NameRecognizer::NAME_WORD): McKellen,
     * O'Brien, Zeta-Jones.
     */
    protected function word(): string
    {
        return self::NAME_WORD;
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
