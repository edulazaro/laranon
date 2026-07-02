<?php

namespace EduLazaro\Laranon\Support;

/**
 * Resolves overlapping spans: longest match wins, then higher confidence.
 * Returns the surviving spans ordered by position.
 */
final class SpanResolver
{
    /**
     * @param array<int, Span> $spans
     * @return array<int, Span>
     */
    public static function resolve(array $spans): array
    {
        usort($spans, function (Span $a, Span $b) {
            return [$b->length, $b->confidence, $a->start]
                <=> [$a->length, $a->confidence, $b->start];
        });

        $kept = [];

        foreach ($spans as $span) {
            foreach ($kept as $existing) {
                if ($span->overlaps($existing)) {
                    continue 2;
                }
            }

            $kept[] = $span;
        }

        usort($kept, fn (Span $a, Span $b) => $a->start <=> $b->start);

        return $kept;
    }
}
