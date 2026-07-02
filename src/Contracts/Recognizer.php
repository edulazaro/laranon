<?php

namespace EduLazaro\Laranon\Contracts;

interface Recognizer
{
    /**
     * The span type this recognizer produces (e.g. 'dni', 'email').
     * Recognizers emitting several types return their default one.
     */
    public function type(): string;

    /**
     * Detect PII occurrences in the given text.
     *
     * @return array<int, \EduLazaro\Laranon\Support\Span>
     */
    public function detect(string $text): array;
}
