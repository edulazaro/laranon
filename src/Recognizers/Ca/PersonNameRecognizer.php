<?php

namespace EduLazaro\Laranon\Recognizers\Ca;

use EduLazaro\Laranon\Recognizers\Es\PersonNameRecognizer as SpanishPersonNameRecognizer;

/**
 * Catalan person names. Catalan documents write names as Spanish ones do
 * (Sr., Sra., Dr., names in capitals), with "i" between surnames ("Pujol i
 * Soley") and "dels" among the particles, both covered by the shared rules,
 * and the INE census, which covers Catalonia, holds their names. What is
 * Catalan here is the list of given names that are also words (Llum, Neus,
 * Roser, Pau).
 */
class PersonNameRecognizer extends SpanishPersonNameRecognizer
{
    protected function grammarLanguage(): ?string
    {
        return 'ca';
    }

    protected function ambiguousNamesFile(): ?string
    {
        return 'ca/names_ambiguous.php';
    }

    /**
     * The Catalan list together with the Spanish one: Catalan texts use
     * Spanish names too (Rosa, Pilar, Dolores).
     *
     * @return array<string, true>
     */
    protected function ambiguousNames(): array
    {
        return $this->ambiguousNames ??= $this->loadNames('es/names_ambiguous.php') + $this->loadNames('ca/names_ambiguous.php');
    }
}
