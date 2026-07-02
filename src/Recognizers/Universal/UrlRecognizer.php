<?php

namespace EduLazaro\Laranon\Recognizers\Universal;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

class UrlRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'url';
    }

    protected function patterns(): array
    {
        return [
            '/\bhttps?:\/\/[^\s<>"\')\]]+/i' => 1.0,
            '/\bwww\.[^\s<>"\')\]]+/i' => 0.9,
        ];
    }
}
