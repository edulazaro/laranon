<?php

use EduLazaro\Laranon\Locales\EnPack;
use EduLazaro\Laranon\Locales\EsPack;
use EduLazaro\Laranon\Recognizers\Universal\BicSwiftRecognizer;
use EduLazaro\Laranon\Recognizers\Universal\CreditCardRecognizer;
use EduLazaro\Laranon\Recognizers\Universal\EmailRecognizer;
use EduLazaro\Laranon\Recognizers\Universal\IbanRecognizer;
use EduLazaro\Laranon\Recognizers\Universal\IpAddressRecognizer;
use EduLazaro\Laranon\Recognizers\Universal\PhoneRecognizer;
use EduLazaro\Laranon\Recognizers\Universal\UrlRecognizer;
use EduLazaro\Laranon\Strategies\FakerStrategy;
use EduLazaro\Laranon\Strategies\RedactStrategy;
use EduLazaro\Laranon\Strategies\TokenStrategy;

return [

    /*
    |--------------------------------------------------------------------------
    | Locale packs
    |--------------------------------------------------------------------------
    |
    | Active locales and the pack class implementing each one. A pack returns
    | the locale-specific recognizers (national ids, phones, person names...).
    | Universal recognizers below run regardless of locale.
    |
    */

    'locales' => ['es', 'en'],

    'packs' => [
        'es' => EsPack::class,
        'en' => EnPack::class,
    ],

    'universal' => [
        EmailRecognizer::class,
        IbanRecognizer::class,
        CreditCardRecognizer::class,
        IpAddressRecognizer::class,
        PhoneRecognizer::class,
        UrlRecognizer::class,
        BicSwiftRecognizer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Replacement strategy
    |--------------------------------------------------------------------------
    |
    | token:  «PER_1», «DNI_1»... reversible, ideal for LLM chat round-trips.
    | faker:  realistic surrogates with the same format (deterministic seed),
    |         reversible, ideal for document generation.
    | redact: [DNI], [PER]... one-way, ideal for logs and outbound scrubbing.
    |
    */

    'strategy' => 'token',

    'strategies' => [
        'token' => TokenStrategy::class,
        'faker' => FakerStrategy::class,
        'redact' => RedactStrategy::class,
    ],

    'token_format' => '«%s_%d»',

    'labels' => [
        'person' => 'PER',
        'surname' => 'AP',
        'email' => 'EMAIL',
        'dni' => 'DNI',
        'nie' => 'NIE',
        'cif' => 'CIF',
        'nss' => 'NSS',
        'ccc' => 'CCC',
        'iban' => 'IBAN',
        'phone' => 'TEL',
        'postal_code' => 'CP',
        'plate' => 'MAT',
        'case_ref' => 'EXP',
        'credit_card' => 'CARD',
        'ip' => 'IP',
        'url' => 'URL',
        'bic' => 'BIC',
        'ssn' => 'SSN',
        'nino' => 'NINO',
        'passport' => 'PASS',
        'zip' => 'ZIP',
        'entity' => 'ENT',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vault
    |--------------------------------------------------------------------------
    |
    | Where scoped token maps are persisted between calls so «PER_1» stays the
    | same person across a whole conversation. Payloads are encrypted with the
    | app key. Stores: cache, database, array (in-memory, single request).
    |
    */

    'vault' => [
        'store' => 'cache',
        'cache_prefix' => 'laranon:',
        'ttl' => 21600,
        'table' => 'laranon_vaults',
    ],

    /*
    |--------------------------------------------------------------------------
    | Gazetteers
    |--------------------------------------------------------------------------
    |
    | Path to the name dictionaries used by the person-name recognizers.
    | Null uses the datasets bundled with the package (data/ directory).
    |
    */

    'data_path' => null,

];
