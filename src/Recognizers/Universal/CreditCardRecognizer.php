<?php

namespace EduLazaro\Laranon\Recognizers\Universal;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;
use EduLazaro\Laranon\Support\Separators;

class CreditCardRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'credit_card';
    }

    protected function patterns(): array
    {
        // Groups split by a blank or a dash, never by a line break: a column
        // of amounts ("4500⏎1000⏎1800⏎1300") can pass Luhn by chance.
        return [
            '/(?<!\d)(?:\d(?:' . Separators::BLANK . '|' . Separators::DASH . ')?){12,18}\d(?!\d)/' => 0.95,
        ];
    }

    protected function validate(string $value): bool
    {
        $digits = Separators::strip($value);
        $length = strlen($digits);

        // Known IIN leading digits (Visa, Mastercard, Amex/Diners, Discover)
        // on top of Luhn: bare digit runs that pass Luhn by chance are out.
        return $length >= 13
            && $length <= 19
            && in_array($digits[0], ['3', '4', '5', '6'], true)
            && Checksums::luhn($digits);
    }
}
