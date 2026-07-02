<?php

namespace EduLazaro\Laranon\Locales;

use EduLazaro\Laranon\Contracts\LocalePack;
use EduLazaro\Laranon\Recognizers\Es\CccRecognizer;
use EduLazaro\Laranon\Recognizers\Es\CifRecognizer;
use EduLazaro\Laranon\Recognizers\Es\DniRecognizer;
use EduLazaro\Laranon\Recognizers\Es\NieRecognizer;
use EduLazaro\Laranon\Recognizers\Es\NigRecognizer;
use EduLazaro\Laranon\Recognizers\Es\NssRecognizer;
use EduLazaro\Laranon\Recognizers\Es\PersonNameRecognizer;
use EduLazaro\Laranon\Recognizers\Es\PhoneRecognizer;
use EduLazaro\Laranon\Recognizers\Es\PlateRecognizer;
use EduLazaro\Laranon\Recognizers\Es\PostalCodeRecognizer;

class EsPack implements LocalePack
{
    public function __construct(protected ?string $dataPath = null)
    {
    }

    public function locale(): string
    {
        return 'es';
    }

    public function recognizers(): array
    {
        return [
            new DniRecognizer(),
            new NieRecognizer(),
            new CifRecognizer(),
            new NssRecognizer(),
            new CccRecognizer(),
            new PhoneRecognizer(),
            new PostalCodeRecognizer(),
            new PlateRecognizer(),
            new NigRecognizer(),
            new PersonNameRecognizer($this->dataPath),
        ];
    }
}
