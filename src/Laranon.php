<?php

namespace EduLazaro\Laranon;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \EduLazaro\Laranon\Anonymizer scope(?string $scope)
 * @method static \EduLazaro\Laranon\Anonymizer strategy(\EduLazaro\Laranon\Contracts\Strategy|string $strategy)
 * @method static \EduLazaro\Laranon\Anonymizer only(array|string $types)
 * @method static \EduLazaro\Laranon\Anonymizer except(array|string $types)
 * @method static \EduLazaro\Laranon\Anonymizer withEntities(array $entities, string $defaultType = 'entity')
 * @method static \EduLazaro\Laranon\Anonymizer withModels(iterable $models)
 * @method static \EduLazaro\Laranon\Anonymizer ttl(?int $seconds)
 * @method static \EduLazaro\Laranon\Support\AnonymizedText anonymize(string $text)
 * @method static array scan(string $text)
 * @method static string deanonymize(string $text, \EduLazaro\Laranon\Support\TokenMap|\EduLazaro\Laranon\Support\AnonymizedText|array|null $map = null)
 * @method static \EduLazaro\Laranon\Streaming\StreamDeanonymizer stream(\EduLazaro\Laranon\Support\TokenMap|\EduLazaro\Laranon\Support\AnonymizedText|null $map = null)
 * @method static void forget()
 *
 * @see \EduLazaro\Laranon\Anonymizer
 */
class Laranon extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laranon';
    }
}
