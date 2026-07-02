<?php

namespace EduLazaro\Laranon\Support;

/**
 * A detected PII occurrence. Offsets are byte-based (as returned by
 * PREG_OFFSET_CAPTURE) and must be used with byte-based string functions.
 */
final class Span
{
    public function __construct(
        public readonly int $start,
        public readonly int $length,
        public readonly string $type,
        public readonly string $value,
        public readonly float $confidence = 1.0,
    ) {
    }

    public function end(): int
    {
        return $this->start + $this->length;
    }

    public function overlaps(Span $other): bool
    {
        return $this->start < $other->end() && $other->start < $this->end();
    }
}
