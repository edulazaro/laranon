<?php

namespace EduLazaro\Laranon\Locales;

use EduLazaro\Laranon\Contracts\LocalePack;
use EduLazaro\Laranon\Recognizers\Nl\PersonNameRecognizer;

/**
 * Dutch person names, for the texts written in Dutch. Dutch
 * identifiers and phones with no country prefix are not covered yet; IBANs,
 * cards and prefixed phones are, by the universal recognizers.
 */
class NlPack implements LocalePack
{
    public function __construct(protected ?string $dataPath = null)
    {
    }

    public function locale(): string
    {
        return 'nl';
    }

    public function recognizers(): array
    {
        return [
            new PersonNameRecognizer($this->dataPath),
        ];
    }
}
