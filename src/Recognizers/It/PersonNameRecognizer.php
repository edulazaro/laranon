<?php

namespace EduLazaro\Laranon\Recognizers\It;

use EduLazaro\Laranon\Recognizers\Fr\PersonNameRecognizer as FrenchPersonNameRecognizer;

/**
 * Italian person names. Italian documents write the surname in capitals
 * after the given name or before it ("Mario ROSSI", "ROSSI Mario"), as
 * French ones do, with name particles in any case ("De Luca", "Di Stefano",
 * "Lo Bianco"). There is no open list of Italian names to download, so the
 * names are those of the INE census, which holds the many Italians living
 * in Spain, plus the frequent names of every other language. Their gender
 * is left neutral: Andrea, Simone or Nicola are men's names in Italy and
 * women's elsewhere.
 */
class PersonNameRecognizer extends FrenchPersonNameRecognizer
{
    protected function grammarLanguage(): ?string
    {
        return 'it';
    }

    protected const CASED_PARTICLES = 'della|dello|delle|degli|dei|dalla|dalle|dallo|dagli|dai|dal|del|di|de|da|lo|la|li';

    protected function surnameHonorificPattern(): ?string
    {
        // Sig., Sig.ra, Dott., Avv., Ing. and the like, and the words in
        // full, introduce a surname (Avv. Rossi) or a full name (Dott.ssa
        // Giulia Bianchi), also one that opens with a particle (Sig. De Luca).
        return '/(?<![\p{L}\p{N}])(?:Sig(?:\.ra|\.na|g)?\.?|Dott(?:\.ssa)?\.?|Dr\.?|Avv\.?|Ing\.?|Prof(?:\.ssa)?\.?|Geom\.?|Rag\.?|Arch\.?|On\.?|'
            . '(?i:Signor|Signora|Signorina|Dottore|Dottoressa|Avvocato|Ingegnere|Professore|Professoressa))\s+'
            . '((?:(?i:' . static::CASED_PARTICLES . ')\s+)?' . $this->honorificName() . ')/u';
    }

    /**
     * Italian particles and honorifics are never name words: "De" in
     * "Daniele De Rossi" stays in cleartext like "de".
     *
     * @return array<int, string>
     */
    protected function articleBlocklist(): array
    {
        return [
            ...parent::articleBlocklist(),
            'di', 'della', 'dello', 'delle', 'degli', 'dei', 'da', 'dal', 'dalla', 'dalle', 'dallo', 'dagli', 'dai', 'li',
            'signor', 'signora', 'signorina', 'dottore', 'dottoressa', 'avvocato', 'ingegnere', 'professore', 'professoressa',
        ];
    }

    protected function firstNamesFile(): string
    {
        return 'es/names.php';
    }

    protected function lastNamesFile(): ?string
    {
        return null;
    }

    protected function ambiguousNamesFile(): ?string
    {
        return 'it/names_ambiguous.php';
    }

    protected function gendersFile(): ?string
    {
        return null;
    }
}
