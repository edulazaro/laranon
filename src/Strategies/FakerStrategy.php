<?php

namespace EduLazaro\Laranon\Strategies;

use EduLazaro\Laranon\Contracts\Strategy;
use EduLazaro\Laranon\Support\Checksums;
use EduLazaro\Laranon\Support\Span;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Realistic surrogates with the same format as the original: a valid fake DNI
 * for a DNI, a fake IBAN for an IBAN, a bare given name for a 'person' word,
 * a bare surname for a 'surname' word. Seeded by (type, value, index), so the
 * TokenMap can regenerate on collision and enforce that no two different
 * values ever share a surrogate. Stability across calls comes from the
 * persisted map (scope), not from the generator. Reversible.
 */
class FakerStrategy implements Strategy
{
    protected const FIRST_NAMES = [
        'Carlos', 'Lucía', 'Andrés', 'Marta', 'Javier', 'Elena', 'Pablo', 'Sara',
        'Hugo', 'Nora', 'Adrián', 'Clara', 'Diego', 'Irene', 'Mario', 'Alba',
    ];

    protected const LAST_NAMES = [
        'Navarro', 'Ortega', 'Serrano', 'Vidal', 'Campos', 'Rey', 'Ibáñez',
        'Marín', 'Pascual', 'Soler', 'Crespo', 'Bravo', 'Lozano', 'Gallego',
    ];

    public function __construct(protected string $format = '«%s_%d»', protected array $labels = [])
    {
    }

    public function replacement(Span $span, int $index): string
    {
        $rng = new Randomizer(new Mt19937(crc32($span->type . '|' . $span->value . '|' . $index)));

        return match ($span->type) {
            'dni' => $this->fakeDni($rng),
            'nie' => $this->fakeNie($rng),
            'person' => self::FIRST_NAMES[$rng->getInt(0, count(self::FIRST_NAMES) - 1)],
            'surname' => self::LAST_NAMES[$rng->getInt(0, count(self::LAST_NAMES) - 1)],
            'email' => 'contacto' . $rng->getInt(100, 9999) . '@example.com',
            'phone' => '6' . str_pad((string) $rng->getInt(0, 99999999), 8, '0', STR_PAD_LEFT),
            'iban' => $this->fakeIban($rng),
            'credit_card' => $this->fakeCard($rng),
            default => $this->fallbackToken($span, $index),
        };
    }

    public function reversible(): bool
    {
        return true;
    }

    protected function fakeDni(Randomizer $rng): string
    {
        $number = $rng->getInt(10000000, 99999999);

        return $number . Checksums::dniLetter($number);
    }

    protected function fakeNie(Randomizer $rng): string
    {
        $prefix = ['X', 'Y', 'Z'][$rng->getInt(0, 2)];
        $digits = str_pad((string) $rng->getInt(0, 9999999), 7, '0', STR_PAD_LEFT);
        $number = (int) (strtr($prefix, ['X' => '0', 'Y' => '1', 'Z' => '2']) . $digits);

        return $prefix . $digits . Checksums::dniLetter($number);
    }

    protected function fakeIban(Randomizer $rng): string
    {
        $bban = '';

        for ($i = 0; $i < 20; $i++) {
            $bban .= $rng->getInt(0, 9);
        }

        $numeric = '';

        foreach (str_split($bban . 'ES00') as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $check = str_pad((string) (98 - Checksums::mod97($numeric)), 2, '0', STR_PAD_LEFT);

        return 'ES' . $check . $bban;
    }

    protected function fakeCard(Randomizer $rng): string
    {
        $digits = '4';

        for ($i = 0; $i < 14; $i++) {
            $digits .= $rng->getInt(0, 9);
        }

        for ($check = 0; $check <= 9; $check++) {
            if (Checksums::luhn($digits . $check)) {
                return $digits . $check;
            }
        }

        return $digits . '0';
    }

    protected function fallbackToken(Span $span, int $index): string
    {
        $label = $this->labels[$span->type] ?? strtoupper($span->type);

        return sprintf($this->format, $label, $index);
    }
}
