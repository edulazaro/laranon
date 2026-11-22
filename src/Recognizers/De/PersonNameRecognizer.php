<?php

namespace EduLazaro\Laranon\Recognizers\De;

use EduLazaro\Laranon\Recognizers\NameRecognizer;

/**
 * German person names. German capitalizes every noun, so after the given
 * name only known surnames are taken into the name, or any word right after
 * a particle, since a particle only comes before a surname: "Peter Müller"
 * and "Ursula von der Leyen" yes, "Peter Geld" in "hat Peter Geld bekommen"
 * no. There is no open list of German names to download, so the names and
 * surnames are those of the INE census, which holds the Germans living in
 * Spain, plus the frequent ones of every other language, with a neutral
 * gender.
 */
class PersonNameRecognizer extends NameRecognizer
{
    protected function surnameHonorificPattern(): ?string
    {
        // Herr, Frau, Hr. and Fr., with the academic titles that follow them
        // (Frau Dr. Angela Merkel), or Dr. and Prof. alone, introduce a
        // surname (Herr Müller) or a full name, also one that opens with a
        // particle (Frau von der Leyen).
        return '/(?<![\p{L}\p{N}])(?:(?:Herrn?|Frau|Hr\.|Fr\.)\s+(?:(?:Dr|Prof)\.\s+)*|(?:Dr|Prof)\.\s+)'
            . '((?:(?:' . self::PARTICLES . ')\s+)?' . $this->name(0) . ')/u';
    }

    protected function namePattern(): string
    {
        return '/(?<![\p{L}\p{N}])' . $this->name(1) . '(?![\p{L}\p{N}])/u';
    }

    /**
     * A name word followed by at least $more (and up to 3) more, joined by
     * spaces, particles (von der Leyen) or initials.
     */
    protected function name(int $more): string
    {
        $next = '(?:' . self::NAME_WORD . '|' . self::PREFIXED_WORD . ')';

        return self::NAME_WORD . '(?:\s+(?:' . self::PARTICLES . ')\s+' . $next . '|\s+' . self::INITIAL . '(?:\s+' . self::INITIAL . ')*\s+' . $next . '|\s+' . $next . '){' . $more . ',3}';
    }

    /**
     * The name ends at the first capitalized word that is neither a known
     * surname nor right after a particle: a German noun.
     */
    protected function resolveCandidate(string $value, int $offset): array
    {
        preg_match_all('/\S+/u', $value, $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0];

        if ($tokens === []) {
            return [];
        }

        $end = strlen($tokens[0][0]);
        $afterParticle = false;

        foreach (array_slice($tokens, 1) as [$word, $relative]) {
            if (preg_match('/^\p{Ll}/u', $word) === 1) {
                $afterParticle = true;

                continue;
            }

            if (! $afterParticle && ! $this->isSurname($word)) {
                break;
            }

            $end = $relative + strlen($word);
            $afterParticle = false;
        }

        return parent::resolveCandidate(substr($value, 0, $end), $offset);
    }

    protected function firstNamesFile(): string
    {
        return 'es/names.php';
    }

    protected function lastNamesFile(): ?string
    {
        return 'es/surnames.php';
    }

    protected function gendersFile(): ?string
    {
        return null;
    }
}
