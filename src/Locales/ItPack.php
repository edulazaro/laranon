<?php

namespace EduLazaro\Laranon\Locales;

use EduLazaro\Laranon\Contracts\LocalePack;
use EduLazaro\Laranon\Recognizers\It\PersonNameRecognizer;

/**
 * Italian person names, for the texts written in Italian. Italian
 * identifiers (codice fiscale, partita IVA) are not covered yet; IBANs,
 * cards and +39 phones are, by the universal recognizers.
 */
class ItPack implements LocalePack
{
    public function __construct(protected ?string $dataPath = null)
    {
    }

    public function locale(): string
    {
        return 'it';
    }

    public function recognizers(): array
    {
        return [
            new PersonNameRecognizer($this->dataPath),
        ];
    }
}
