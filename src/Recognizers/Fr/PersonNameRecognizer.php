<?php

namespace EduLazaro\Laranon\Recognizers\Fr;

use EduLazaro\Laranon\Recognizers\NameRecognizer;
use EduLazaro\Laranon\Support\Span;

/**
 * French person names. French documents write the surname in capitals,
 * after the given name ("Jean DUPONT") or before it ("DUPONT Jean"), and
 * name particles in any case ("de Gaulle", "Le Pen", "La Fontaine").
 */
class PersonNameRecognizer extends NameRecognizer
{
    protected function grammarLanguage(): ?string
    {
        return 'fr';
    }

    /**
     * Name particles, matched in any case.
     */
    protected const CASED_PARTICLES = 'de\s+la|de|du|des|le|la';

    /**
     * A surname in capitals: DUPONT, D'ARTAGNAN, SAINT-EXUPÉRY.
     */
    protected const CAPS_WORD = '(?:\p{Lu}[\'’])?\p{Lu}{2,}(?:[-\x{2010}\x{2011}]\p{Lu}{2,})*';

    protected function surnameHonorificPattern(): ?string
    {
        // M., Mme, Mlle, Me (maître, for lawyers), Dr and Pr, and the words
        // in full, introduce a surname (M. Dupont, Me DURAND) or a full name
        // (Mme Marie Dupont), also one that opens with a particle (M. de
        // Villepin).
        return '/(?<![\p{L}\p{N}])(?:M\.|MM\.|Mmes?\.?|Mlles?\.?|Me\.?|Dr\.?|Pr\.?|(?i:Monsieur|Messieurs|Madame|Mesdames|Mademoiselle|Ma[iî]tre|Docteur|Professeur))\s+'
            . '((?:(?i:' . static::CASED_PARTICLES . ')\s+)?' . $this->honorificName() . ')/u';
    }

    /**
     * The name after an honorific: up to four words, in any case, each of
     * two letters or more.
     */
    protected function honorificName(): string
    {
        $word = '\p{Lu}[\p{L}\'’]+(?:' . self::HYPHEN . '\p{Lu}[\p{L}\'’]+)*(?<![\'’])';
        $next = '(?:' . $word . '|' . self::PREFIXED_WORD . ')';

        return $word . '(?:\s+(?i:' . static::CASED_PARTICLES . ')\s+' . $next . '|\s+' . self::INITIAL . '(?:\s+' . self::INITIAL . ')*\s+' . $next . '|\s+' . $next . '){0,3}';
    }

    protected function namePattern(): string
    {
        // A given name, then surnames in capitals or not: "Jean DUPONT",
        // "Jean-Pierre Martin", "Charles de Gaulle", "Marine Le Pen".
        $next = '(?:' . self::NAME_WORD . '|' . self::CAPS_WORD . '|' . self::PREFIXED_WORD . ')';

        return '/(?<![\p{L}\p{N}])' . self::NAME_WORD
            . '(?:\s+(?i:' . static::CASED_PARTICLES . ')\s+' . $next . '|\s+' . self::INITIAL . '(?:\s+' . self::INITIAL . ')*\s+' . $next . '|\s+' . $next . '){1,3}(?![\p{L}\p{N}])/u';
    }

    /**
     * A surname in capitals must be a known one, so a capitalized word after
     * a name ("Jean DUPONT AVOCAT") is not taken into it.
     */
    protected function resolveCandidate(string $value, int $offset): array
    {
        while (preg_match('/^(.*\S)\s+(\S+)$/su', $value, $m) === 1 && $this->isCaps($m[2]) && ! $this->isSurname($m[2])) {
            $value = $m[1];
        }

        return parent::resolveCandidate($value, $offset);
    }

    /**
     * The surname first, in capitals, then the given name: "DUPONT Jean",
     * "LE GALL Yann", as forms and registers write it. Only within a line.
     */
    protected function upperNamePattern(): ?string
    {
        return '/(?<![\p{L}\p{N}])' . self::CAPS_WORD
            . '(?:\h+(?i:' . static::CASED_PARTICLES . ')\h+' . self::CAPS_WORD . '|\h+' . self::CAPS_WORD . '){0,2}'
            . '\h+' . self::NAME_WORD . '(?:\h+' . self::NAME_WORD . ')?(?![\p{L}\p{N}])/u';
    }

    /**
     * "DUPONT Jean" opens with its surname.
     */
    protected function opensUpperName(string $word): bool
    {
        return $this->isCaps($word) && $this->isSurname($word);
    }

    /**
     * Every word in capitals must be a known surname and the first one after
     * them a known given name; the given name carries the gazetteer's
     * gender, and a second given name is typed like the others after it.
     *
     * @return array<int, Span>
     */
    protected function resolveUpperCandidate(string $value, int $offset): array
    {
        preg_match_all('/\S+/u', $value, $matches, PREG_OFFSET_CAPTURE);
        $blocklist = array_fill_keys($this->articleBlocklist(), true);

        $surnames = [];
        $given = [];

        foreach ($matches[0] as [$word, $relative]) {
            if ($given === [] && $this->isCaps($word)) {
                if (isset($blocklist[$this->fold($word)])) {
                    continue;
                }

                if (! $this->isSurname($word)) {
                    return [];
                }

                $surnames[] = [$word, $relative];
            } else {
                $given[] = [$word, $relative];
            }
        }

        if ($surnames === [] || $given === [] || ! $this->opensName($given[0][0])) {
            return [];
        }

        $spans = [];

        foreach ($surnames as [$word, $relative]) {
            $spans[] = new Span($offset + $relative, strlen($word), 'surname', $word, 0.9);
        }

        foreach ($given as $i => [$word, $relative]) {
            $gender = $i === 0 ? $this->genderOf($this->parts($word)[0]) : null;
            $spans[] = new Span($offset + $relative, strlen($word), $i === 0 ? 'person' : 'surname', $word, 0.9, $gender);
        }

        return $spans;
    }

    protected function isCaps(string $word): bool
    {
        return preg_match('/^' . self::CAPS_WORD . '$/u', $word) === 1;
    }

    /**
     * French honorifics are never name words either (the articles, such as
     * "Le" in "Marine Le Pen", already are for every recognizer).
     *
     * @return array<int, string>
     */
    protected function articleBlocklist(): array
    {
        return [
            ...parent::articleBlocklist(),
            'l', 'd', 'm', 'mm', 'mme', 'mmes', 'mlle', 'mlles', 'me', 'pr',
            'monsieur', 'messieurs', 'madame', 'mesdames', 'mademoiselle', 'maitre', 'docteur', 'professeur',
        ];
    }

    protected function joinsWrappedLines(): bool
    {
        return true;
    }

    protected function firstNamesFile(): string
    {
        return 'fr/names.php';
    }

    protected function lastNamesFile(): ?string
    {
        return 'fr/surnames.php';
    }

    protected function ambiguousNamesFile(): ?string
    {
        return 'fr/names_ambiguous.php';
    }

    protected function gendersFile(): ?string
    {
        return 'fr/names_gender.php';
    }
}
