<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;
use EduLazaro\Laranon\Support\Separators;

/**
 * Spanish intra-community VAT number (NIF-IVA): "ES" followed by a DNI, a NIE
 * or a CIF ("ESA58818501", "ES 12345678Z"), validated with the checksum of
 * whichever of the three follows. The prefix is uppercase only: in "El NIF es
 * 12345678Z" it is the verb, and the DNI recognizer takes the number.
 */
class VatRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'vat';
    }

    protected function patterns(): array
    {
        return [
            '/(?<![\dA-Za-z])ES' . Separators::GAP . '[0-9A-Z]\d{7}[0-9A-Z](?![\dA-Za-z])/' => 1.0,
        ];
    }

    protected function validate(string $value): bool
    {
        $number = substr(Separators::strip($value), 2);
        $first = $number[0] ?? '';

        return match (true) {
            ctype_digit($first) => Checksums::dni($number),
            in_array($first, ['X', 'Y', 'Z'], true) => Checksums::nie($number),
            default => Checksums::cif($number),
        };
    }
}
