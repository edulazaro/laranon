<?php

namespace EduLazaro\Laranon\Locales;

use EduLazaro\Laranon\Contracts\LocalePack;
use EduLazaro\Laranon\Recognizers\Ca\PersonNameRecognizer;

/**
 * Catalan person names. The Spanish numbers and identifiers (phones,
 * postal codes, DNI, NIE, NSS...) come with the 'es' pack and run on every
 * text, whatever its language. Andorran ones are not covered yet.
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
            new PersonNameRecognizer($this->dataPath),
        ];
    }
}
