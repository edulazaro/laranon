<?php

namespace EduLazaro\Laranon\Recognizers\Es;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Separators;

/**
 * Modern Spanish plates: 4 digits + 3 consonants (no vowels, no Q/Ñ). Also
 * the provincial ones issued until 2000 ("M-1234-AB"), context-gated.
 */
class PlateRecognizer extends RegexRecognizer
{
    /**
     * Province codes of the plates issued until 2000.
     */
    protected const PROVINCES = 'A|AB|AL|AV|B|BA|BI|BU|C|CA|CC|CE|CO|CR|CS|CU|GC|GE|GI|GR|GU|H|HU|IB|J|L|LE|LO|LU'
        . '|M|MA|ML|MU|NA|O|OR|OU|P|PM|PO|S|SA|SE|SG|SO|SS|T|TE|TF|TO|V|VA|VI|Z|ZA';

    public function type(): string
    {
        return 'plate';
    }

    protected function patterns(): array
    {
        return [
            // Not right after a slash: "50/1980 LCS" is the year of a
            // number/year reference followed by an acronym.
            '/(?<![\dA-Za-z\/])\d{4}' . Separators::GAP . '[BCDFGHJKLMNPRSTVWXYZ]{3}(?![\dA-Za-z])/' => 0.85,
            // A provincial plate looks like any reference code, so it is only
            // taken after a word that introduces a plate, on the same line,
            // and with the same separator on both sides of the digits.
            '/(?i:matr[ií]cula|placa|veh[ií]culo)[^\n]{0,30}?(?<![\p{L}\p{N}])'
                . '((?:' . self::PROVINCES . ')([\s\-\x{2010}-\x{2015}\x{2212}]?)\d{4}\2[A-Z]{1,2})(?![\p{L}\p{N}])/u' => 0.85,
        ];
    }
}
