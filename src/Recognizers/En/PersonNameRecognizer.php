<?php

namespace EduLazaro\Laranon\Recognizers\En;

use EduLazaro\Laranon\Recognizers\NameRecognizer;

class PersonNameRecognizer extends NameRecognizer
{
    protected function honorificPattern(): ?string
    {
        // Sir/Lady conventionally precede a given name (Sir Ian, Lady Diana).
        return '/\b(?:Sir|Lady)\s+(' . $this->name(0) . ')/u';
    }

    protected function surnameHonorificPattern(): ?string
    {
        // Mr/Mrs/Ms/Dr/Prof conventionally precede a surname (Mr. Smith), also
        // one that opens with a particle (Mr. van Gogh).
        return '/\b(?:Mr|Mrs|Ms|Miss|Dr|Prof)\.?\s+((?:(?:' . self::PARTICLES . ')\s+)?' . $this->name(0) . ')/u';
    }

    protected function namePattern(): string
    {
        return '/(?<![\p{L}\p{N}])' . $this->name(1) . '(?![\p{L}\p{N}])/u';
    }

    /**
     * A capitalized name word (see NameRecognizer::NAME_WORD): McKellen,
     * O'Brien, Zeta-Jones.
     */
    protected function word(): string
    {
        return self::NAME_WORD;
    }

    /**
     * A name word followed by at least $more (and up to 3) more, joined by
     * spaces or by particles (Vincent van Gogh, Leonardo da Vinci).
     */
    protected function name(int $more): string
    {
        $next = '(?:' . self::NAME_WORD . '|' . self::PREFIXED_WORD . ')';

        return self::NAME_WORD . '(?:\s+(?:' . self::PARTICLES . ')\s+' . $next . '|\s+' . $next . '){' . $more . ',3}';
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
