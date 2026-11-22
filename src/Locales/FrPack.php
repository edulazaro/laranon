<?php

namespace EduLazaro\Laranon\Locales;

use EduLazaro\Laranon\Contracts\LocalePack;
use EduLazaro\Laranon\Recognizers\Fr\PersonNameRecognizer;

/**
 * French person names, for the texts written in French. French
 * identifiers (NIR, SIREN, SIRET) are not covered yet; IBANs, cards and +33
 * phones are, by the universal recognizers.
 */
class FrPack implements LocalePack
{
    public function __construct(protected ?string $dataPath = null)
    {
    }

    public function locale(): string
    {
        return 'fr';
    }

    public function recognizers(): array
    {
        return [
            new PersonNameRecognizer($this->dataPath),
        ];
    }
}
