<?php

namespace EduLazaro\Laranon\Locales;

use EduLazaro\Laranon\Contracts\LocalePack;
use EduLazaro\Laranon\Recognizers\Ca\PersonNameRecognizer;
use EduLazaro\Laranon\Recognizers\Es\NigRecognizer;
use EduLazaro\Laranon\Recognizers\Es\PhoneRecognizer;
use EduLazaro\Laranon\Recognizers\Es\PlateRecognizer;
use EduLazaro\Laranon\Recognizers\Es\PostalCodeRecognizer;

/**
 * Catalan texts: their person names, and the Spanish numbers they carry,
 * since a Catalan text is nearly always from Spain (phones with no
 * prefix, postal codes, plates, court references). The Spanish identifiers
 * with a checksum (DNI, NIE, NSS...) come with the 'es' pack and run on
 * every text. Andorran ones are not covered yet.
 */
class CaPack implements LocalePack
{
    public function __construct(protected ?string $dataPath = null)
    {
    }

    public function locale(): string
    {
        return 'ca';
    }

    public function recognizers(): array
    {
        return [
            new PhoneRecognizer(),
            new PostalCodeRecognizer(),
            new PlateRecognizer(),
            new NigRecognizer(),
            new PersonNameRecognizer($this->dataPath),
        ];
    }
}
