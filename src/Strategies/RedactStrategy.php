<?php

namespace EduLazaro\Laranon\Strategies;

use EduLazaro\Laranon\Contracts\Strategy;
use EduLazaro\Laranon\Support\Span;

/**
 * One-way redaction: [DNI], [PER]... Nothing is vaulted, nothing can be
 * reversed. The right strategy for logs and outbound scrubbing.
 */
class RedactStrategy implements Strategy
{
    public function __construct(protected array $labels = [])
    {
    }

    public function replacement(Span $span, int $index): string
    {
        $label = $this->labels[$span->type] ?? strtoupper($span->type);

        return '[' . $label . ']';
    }

    public function reversible(): bool
    {
        return false;
    }
}
