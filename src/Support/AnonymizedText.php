<?php

namespace EduLazaro\Laranon\Support;

use Stringable;

/**
 * Result of an anonymize() call: the scrubbed text plus the token map needed
 * to restore any response built on top of it.
 */
final class AnonymizedText implements Stringable
{
    public function __construct(
        public readonly string $text,
        public readonly TokenMap $map,
    ) {
    }

    /**
     * Restore tokens in a text produced from the anonymized version
     * (e.g. the LLM response).
     */
    public function restore(string $text): string
    {
        return $this->map->restore($text);
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
