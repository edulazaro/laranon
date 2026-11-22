<?php

namespace EduLazaro\Laranon\Recognizers\En;

use EduLazaro\Laranon\Recognizers\NameRecognizer;

class PersonNameRecognizer extends NameRecognizer
{
    protected function grammarLanguage(): ?string
    {
        return 'en';
    }

    /**
     * The particles of English-language names (van Gogh, da Vinci, bin
     * Salman), without the Spanish ones (de, del, de la, la, lo): the Spanish
     * recognizer runs on the same text and takes those names, and here they
     * made headings like "Dirección General de Seguridad Jurídica" a name,
     * since the US records list General and Valor as given names.
     */
    protected const EN_PARTICLES = 'della|dello|delle|degli|dei|des|du|das|da|dos|do|di|le'
        . '|van(?:\s+de[rn]?)?|von(?:\s+der)?|der|den|ten|ter|bint|bin|ibn|ben';

    protected function honorificPattern(): ?string
    {
        // Sir/Lady conventionally precede a given name (Sir Ian, Lady Diana).
        return '/\b(?:Sir|Lady)\s+(' . $this->name(0) . ')/u';
    }

    protected function surnameHonorificPattern(): ?string
    {
        // Mr/Mrs/Ms/Dr/Prof conventionally precede a surname (Mr. Smith), also
        // one that opens with a particle (Mr. van Gogh).
        return '/\b(?:Mr|Mrs|Ms|Miss|Dr|Prof)\.?\s+((?:(?:' . self::EN_PARTICLES . ')\s+)?' . $this->name(0) . ')/u';
    }

    protected function namePattern(): string
    {
        return '/(?<![\p{L}\p{N}])' . $this->name(1) . '(?![\p{L}\p{N}])/u';
    }

    protected function upperNamePattern(): ?string
    {
        // All-caps names ("JOHN DOE v. RICHARD ROE"), as captions and court
        // documents write them, only within a line, like the Spanish ones.
        $part = '(?:\p{Lu}[\'’])?\p{Lu}{2,}';
        $word = $part . '(?:' . self::HYPHEN . $part . ')*';

        return '/(?<![\p{L}\p{N}])' . $word
            . '(?:\h+' . self::INITIAL . '\h+' . $word . '|\h+' . $word . '){1,3}(?![\p{L}\p{N}])/u';
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
     * spaces, particles (Vincent van Gogh) or initials (John F. Kennedy).
     */
    protected function name(int $more): string
    {
        $next = '(?:' . self::NAME_WORD . '|' . self::PREFIXED_WORD . ')';

        return self::NAME_WORD . '(?:\s+(?:' . self::EN_PARTICLES . ')\s+' . $next . '|\s+' . self::INITIAL . '(?:\s+' . self::INITIAL . ')*\s+' . $next . '|\s+' . $next . '){' . $more . ',3}';
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

    /**
     * Only a frequent given name opens a name in capitals: the census lists
     * rare ones that are words ("GENERAL MOTORS").
     */
    protected function commonNamesFile(): ?string
    {
        return 'en/names_frequent.php';
    }

    protected function gendersFile(): ?string
    {
        return 'en/names_gender.php';
    }
}
