<?php

namespace EduLazaro\Laranon\Recognizers\Universal;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;

class BicSwiftRecognizer extends RegexRecognizer
{
    protected const COUNTRIES = [
        'AD', 'AE', 'AR', 'AT', 'AU', 'BE', 'BG', 'BO', 'BR', 'CA', 'CH', 'CL',
        'CN', 'CO', 'CR', 'CU', 'CY', 'CZ', 'DE', 'DK', 'DO', 'DZ', 'EC', 'EE',
        'EG', 'ES', 'FI', 'FR', 'GB', 'GR', 'GT', 'HK', 'HN', 'HR', 'HU', 'IE',
        'IL', 'IN', 'IT', 'JP', 'KR', 'KW', 'LT', 'LU', 'LV', 'MA', 'MC', 'MT',
        'MX', 'NI', 'NL', 'NO', 'NZ', 'PA', 'PE', 'PL', 'PT', 'PY', 'QA', 'RO',
        'RS', 'RU', 'SA', 'SE', 'SG', 'SI', 'SK', 'SM', 'SV', 'TN', 'TR', 'UA',
        'US', 'UY', 'VE', 'ZA',
    ];

    public function type(): string
    {
        return 'bic';
    }

    protected function patterns(): array
    {
        return [
            '/\b[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?\b/' => 0.75,
        ];
    }

    protected function validate(string $value): bool
    {
        return in_array(substr($value, 4, 2), self::COUNTRIES, true);
    }
}
