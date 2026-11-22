<?php

namespace EduLazaro\Laranon\Recognizers\Pt;

use EduLazaro\Laranon\Recognizers\Es\PersonNameRecognizer as SpanishPersonNameRecognizer;

/**
 * Portuguese person names. Portuguese writes names as Spanish does, with
 * Dom and Dona among the honorifics and da, do, dos, das among the
 * particles ("João da Silva", "Maria dos Santos"). There is no open list of
 * Portuguese names to download, so the names and surnames are those of the
 * INE census, which holds the Portuguese living in Spain (and most
 * Portuguese names are Spanish ones too), plus the frequent ones of every
 * other language, with a neutral gender.
 */
class PersonNameRecognizer extends SpanishPersonNameRecognizer
{
    protected function grammarLanguage(): ?string
    {
        return 'pt';
    }

    protected function honorificPattern(): ?string
    {
        // Dom, Dona and D. introduce a given name (Dona Maria, D. João).
        return '/(?:\b(?i:Dom|Dona)|(?<!\p{L})D\.)\s+(' . $this->honorificName() . ')/u';
    }

    protected function surnameHonorificPattern(): ?string
    {
        // Sr., Sra., Dr., Dra., Exmo. and Exma. introduce a surname (Sr.
        // Silva) or a full name, also one that opens with a particle.
        return '/(?<!\p{L})(?i:Srta|Sra|Sr|Dra|Dr|Exmo|Exma)\.?\s+((?:(?i:' . self::PARTICLES . ')\s+)?' . $this->honorificName() . ')/u';
    }

    /**
     * Portuguese particles and honorifics are never name words, in any case.
     *
     * @return array<int, string>
     */
    protected function articleBlocklist(): array
    {
        return [...parent::articleBlocklist(), 'da', 'das', 'do', 'dos', 'dom', 'dona', 'exmo', 'exma'];
    }

    protected function ambiguousNamesFile(): ?string
    {
        return 'pt/names_ambiguous.php';
    }

    /**
     * The Portuguese list together with the Spanish one.
     *
     * @return array<string, true>
     */
    protected function ambiguousNames(): array
    {
        return $this->ambiguousNames ??= $this->loadNames('es/names_ambiguous.php') + $this->loadNames('pt/names_ambiguous.php');
    }

    protected function gendersFile(): ?string
    {
        return null;
    }
}
