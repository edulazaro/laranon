<?php

namespace EduLazaro\Laranon\Contracts;

interface LocalePack
{
    public function locale(): string;

    /**
     * Locale-specific recognizers. Universal recognizers (email, IBAN...)
     * are configured separately and must not be duplicated here.
     *
     * @return array<int, Recognizer>
     */
    public function recognizers(): array;
}
