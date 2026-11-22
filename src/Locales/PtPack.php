<?php

namespace EduLazaro\Laranon\Locales;

use EduLazaro\Laranon\Contracts\LocalePack;
use EduLazaro\Laranon\Recognizers\Pt\PersonNameRecognizer;

/**
 * Portuguese person names, for the texts written in Portuguese. Portuguese
 * identifiers and phones with no country prefix are not covered yet; IBANs,
 * cards and prefixed phones are, by the universal recognizers.
 */
class PtPack implements LocalePack
{
    public function __construct(protected ?string $dataPath = null)
    {
    }

    public function locale(): string
    {
        return 'pt';
    }

    public function recognizers(): array
    {
        return [
            new PersonNameRecognizer($this->dataPath),
        ];
    }
}
