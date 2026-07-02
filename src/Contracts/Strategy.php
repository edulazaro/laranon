<?php

namespace EduLazaro\Laranon\Contracts;

use EduLazaro\Laranon\Support\Span;

interface Strategy
{
    /**
     * Build the replacement string for a detected span. The index is stable
     * per type within a token map («PER_1», «PER_2»...).
     */
    public function replacement(Span $span, int $index): string;

    /**
     * Whether replacements can be mapped back to the original values.
     * One-way strategies (redact) return false and nothing is vaulted.
     */
    public function reversible(): bool;
}
