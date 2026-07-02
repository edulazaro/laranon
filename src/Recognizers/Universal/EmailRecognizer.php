<?php

namespace EduLazaro\Laranon\Recognizers\Universal;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

class EmailRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'email';
    }

    protected function patterns(): array
    {
        return [
            '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/' => 1.0,
        ];
    }
}
