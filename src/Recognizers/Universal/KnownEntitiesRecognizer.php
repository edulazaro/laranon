<?php

namespace EduLazaro\Laranon\Recognizers\Universal;

use EduLazaro\Laranon\Contracts\Recognizer;
use EduLazaro\Laranon\Support\Span;

/**
 * Exact matching of values you already know (your clients' names, emails,
 * addresses pulled from your models). No guessing involved: recall is total
 * on the entities that matter most. Matching is case-insensitive and
 * word-bounded.
 */
class KnownEntitiesRecognizer implements Recognizer
{
    /**
     * @param array<int, array{value: string, type?: string}> $entities
     */
    public function __construct(protected array $entities = [])
    {
    }

    public function type(): string
    {
        return 'entity';
    }

    public function detect(string $text): array
    {
        $spans = [];

        foreach ($this->entities as $entity) {
            $value = trim((string) ($entity['value'] ?? ''));

            if ($value === '' || mb_strlen($value) < 3) {
                continue;
            }

            $type = $entity['type'] ?? 'entity';

            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/iu';

            if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$found, $offset]) {
                $spans[] = new Span($offset, strlen($found), $type, $found, 1.0);
            }
        }

        return $spans;
    }
}
