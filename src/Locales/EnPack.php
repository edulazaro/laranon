<?php

namespace EduLazaro\Laranon\Locales;

use EduLazaro\Laranon\Contracts\LocalePack;
use EduLazaro\Laranon\Recognizers\En\NinoRecognizer;
use EduLazaro\Laranon\Recognizers\En\PassportRecognizer;
use EduLazaro\Laranon\Recognizers\En\PersonNameRecognizer;
use EduLazaro\Laranon\Recognizers\En\PhoneRecognizer;
use EduLazaro\Laranon\Recognizers\En\SsnRecognizer;
use EduLazaro\Laranon\Recognizers\En\ZipCodeRecognizer;

class EnPack implements LocalePack
{
    public function __construct(protected ?string $dataPath = null)
    {
    }

    public function locale(): string
    {
        return 'en';
    }

    public function recognizers(): array
    {
        return [
            new SsnRecognizer(),
            new NinoRecognizer(),
            new PassportRecognizer(),
            new PhoneRecognizer(),
            new ZipCodeRecognizer(),
            new PersonNameRecognizer($this->dataPath),
        ];
    }
}
