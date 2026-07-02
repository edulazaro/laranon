<?php

namespace EduLazaro\Laranon\Recognizers\Universal;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

class IpAddressRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'ip';
    }

    protected function patterns(): array
    {
        return [
            '/(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?![\d.])/' => 0.9,
            '/\b(?:[A-Fa-f0-9]{1,4}:){3,7}[A-Fa-f0-9]{1,4}\b/' => 0.7,
        ];
    }
}
