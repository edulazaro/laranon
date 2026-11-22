<?php

namespace EduLazaro\Laranon\Recognizers\Nl;

use EduLazaro\Laranon\Recognizers\Fr\PersonNameRecognizer as FrenchPersonNameRecognizer;

/**
 * Dutch person names, with their particles in any case ("Jan van der Berg",
 * "Van der Berg") and the surname in capitals that forms use ("Jan DE
 * VRIES"), as French documents do. There is no open list of Dutch names to
 * download, so the names and surnames are those of the INE census, which
 * holds the Dutch living in Spain, plus the frequent ones of every other
 * language, with a neutral gender.
 */
class PersonNameRecognizer extends FrenchPersonNameRecognizer
{
    protected function grammarLanguage(): ?string
    {
        return 'nl';
    }

    protected const CASED_PARTICLES = 'van\s+der|van\s+den|van\s+de|van|de|den|der|ten|ter|te';

    protected function surnameHonorificPattern(): ?string
    {
        // dhr. (de heer), mevr., mw., mr. (meester, for lawyers), dr., drs.,
        // prof., ir. and ing., and "de heer" and "mevrouw" in full,
        // introduce a surname (dhr. Jansen) or a full name, also one that
        // opens with a particle (mevr. van der Berg).
        return '/(?<![\p{L}\p{N}])(?:(?i:dhr|mevr|mw|mr|drs|dr|prof|ir|ing)\.|(?i:de\s+heer|meneer|mevrouw))\s+'
            . '((?:(?i:' . static::CASED_PARTICLES . ')\s+)?' . $this->honorificName() . ')/u';
    }

    /**
     * Dutch particles and honorifics are never name words: "Van" in "Van der
     * Berg" stays in cleartext like "van".
     *
     * @return array<int, string>
     */
    protected function articleBlocklist(): array
    {
        return [
            ...parent::articleBlocklist(),
            'van', 'te', 'dhr', 'mevr', 'mw', 'drs', 'ir', 'ing', 'heer', 'meneer', 'mevrouw',
        ];
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
        return null;
    }

    protected function gendersFile(): ?string
    {
        return null;
    }
}
