<?php

namespace EduLazaro\Laranon\Strategies;

use EduLazaro\Laranon\Contracts\Strategy;
use EduLazaro\Laranon\Support\Span;

/**
 * Typed, indexed placeholders: «PER_1», «DNI_1»... Reversible. The default
 * for LLM chat round-trips: the model reasons over structure, not identities.
 */
class TokenStrategy implements Strategy
{
    public function __construct(
        protected string $format = '«%s_%d»',
        protected array $labels = [],
    ) {
    }

    public function replacement(Span $span, int $index): string
    {
        $label = $this->labels[$span->type] ?? strtoupper($span->type);

        return sprintf($this->format, $label, $index);
    }

    public function reversible(): bool
    {
        return true;
    }
}
