<?php

namespace EduLazaro\Laranon\Strategies;

use EduLazaro\Laranon\Contracts\Strategy;
use EduLazaro\Laranon\Support\Span;

/**
 * Typed, indexed placeholders: «PER_1», «DNI_1»... Reversible. The default
 * for LLM chat round-trips: the model reasons over structure, not identities.
 * With $gender, a given name of known gender says it: «PER_F_1», «PER_M_2»,
 * so the model keeps Spanish agreement without seeing the name.
 */
class TokenStrategy implements Strategy
{
    public function __construct(
        protected string $format = '«%s_%d»',
        protected array $labels = [],
        protected bool $gender = true,
    ) {
    }

    public function replacement(Span $span, int $index): string
    {
        $label = $this->labels[$span->type] ?? strtoupper($span->type);

        if ($this->gender && $span->gender !== null) {
            $label .= '_' . strtoupper($span->gender);
        }

        return sprintf($this->format, $label, $index);
    }

    public function reversible(): bool
    {
        return true;
    }
}
