<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\NameRecognizer;

class PersonNameRecognizer extends NameRecognizer
{
    protected function honorificPattern(): ?string
    {
        // D./D.ª/Dña./Don/Doña introduce a given name (Don Juan, Dña. Luz,
        // D.ª Raquel). Don, Doña and the connectors in any case: the RAE
        // writes "don Juan" and documents write "DON JUAN DE LA CRUZ".
        return '/(?:\b(?i:Don|Doña)|(?<!\p{L})(?:D\.?ª|(?:D|(?i:Dña))\.))\s+(' . $this->honorificName() . ')/u';
    }

    protected function surnameHonorificPattern(): ?string
    {
        // Sr./Sra./Dr./Ldo... introduce a surname (Sr. García, Dra. Vidal), in
        // any case (SR. GARCÍA), also one that opens with a particle (Sr. de
        // la Fuente).
        return '/(?<!\p{L})(?i:Srta|Sra|Sr|Dra|Dr|Lcdo|Lcda|Ldo|Lda)\.?\s+((?:(?i:' . self::PARTICLES . ')\s+)?' . $this->honorificName() . ')/u';
    }

    /**
     * The name after an honorific: up to four words, in any case. Each word
     * has 2+ letters, so in "Sr. D. Luis" the "D" of "D." is never taken for
     * a surname.
     */
    protected function honorificName(): string
    {
        $word = '\p{Lu}[\p{L}\'’]+(?:' . self::HYPHEN . '\p{Lu}[\p{L}\'’]+)*';
        $next = '(?:' . $word . '|' . self::PREFIXED_WORD . ')';

        return $word . '(?:\s+(?i:' . self::PARTICLES . '|y|e)\s+' . $next . '|\s+' . self::INITIAL . '(?:\s+' . self::INITIAL . ')*\s+' . $next . '|\s+' . $next . '){0,3}';
    }

    protected function upperNamePattern(): ?string
    {
        // All-caps names ("JUAN GARCÍA LÓPEZ"), as escrituras and court
        // documents write them. Only within a line: headings sit on their
        // own lines and must not be glued to the name above them.
        $part = '(?:\p{Lu}[\'’])?\p{Lu}{2,}';
        $word = $part . '(?:' . self::HYPHEN . $part . ')*';

        return '/(?<![\p{L}\p{N}])' . $word
            . '(?:\h+(?:DE(?:\h+L(?:A|OS|AS))?|DEL)\h+' . $word . '|\h+' . self::INITIAL . '\h+' . $word . '|\h+' . $word . '){1,3}(?![\p{L}\p{N}])/u';
    }

    protected function namePattern(): string
    {
        // "y"/"e" are deliberately NOT connectors here (unlike the honorific
        // pattern): without an explicit title, "Juan Pérez y María López"
        // is far more often two people in a list than one person's compound
        // surname ("Ortega y Gasset"), and merging them into a single span
        // would misattribute PII across two different people.
        $next = '(?:' . self::NAME_WORD . '|' . self::PREFIXED_WORD . ')';

        return '/(?<![\p{L}\p{N}])' . self::NAME_WORD
            . '(?:\s+(?:' . self::PARTICLES . ')\s+' . $next . '|\s+' . self::INITIAL . '(?:\s+' . self::INITIAL . ')*\s+' . $next . '|\s+' . $next . '){1,3}(?![\p{L}\p{N}])/u';
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

    protected function commonNamesFile(): ?string
    {
        return 'es/names_common.php';
    }

    protected function gendersFile(): ?string
    {
        return 'es/names_gender.php';
    }
}
