<?php

namespace EduLazaro\Laranon\Locales;

use EduLazaro\Laranon\Contracts\LocalePack;
use EduLazaro\Laranon\Recognizers\Ca\PersonNameRecognizer;

/**
 * Catalan person names. Not active by default: add 'ca' to the locales in
 * config/laranon.php. Spanish identifiers (DNI, NIE, NSS...) come with the
 * 'es' pack, and Andorran ones are not covered yet.
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
