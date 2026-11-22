<?php

namespace EduLazaro\Laranon\Contracts;

interface Recognizer
{
    /**
     * The span type this recognizer produces (e.g. 'dni', 'email').
     * Recognizers emitting several types return their default one, and can
     * list all of them in a public types(): array method, which only() uses
     * to reject types no recognizer produces.
     */
    public function type(): string;

    /**
     * Detect PII occurrences in the given text.
     *
     * @return array<int, \EduLazaro\Laranon\Support\Span>
     */
    public function detect(string $text): array;
}
