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
 * a bare surname for a 'surname' word. Deterministic in (type, value, index),
 * so the TokenMap can regenerate on collision and enforce that no two
 * different values ever share a surrogate. Stability across calls comes from
 * the persisted map (scope), not from the generator. Reversible.
 *
 * A surrogate is a real-looking word, so it can also be a real word of the
 * text you restore later: the TokenMap rejects any surrogate already present
 * in the text being anonymized, but it cannot know what a model will write.
 * For free LLM output, prefer the token strategy.
 */
class FakerStrategy implements Strategy
{
    /**
     * Curated so that no entry is also a common Spanish word ("Clara",
     * "Alba", "Rosa" are adjectives or nouns that a model writes often).
     */
    protected const FIRST_NAMES = [
        'Carlos', 'Lucía', 'Andrés', 'Marta', 'Javier', 'Elena', 'Pablo', 'Sara',
        'Hugo', 'Nora', 'Adrián', 'Irene', 'Diego', 'Mario', 'Inés', 'Álvaro',
        'Beatriz', 'Gonzalo', 'Natalia', 'Rubén', 'Silvia', 'Sergio', 'Raquel', 'Óscar',
        'Patricia', 'Víctor', 'Teresa', 'Daniel', 'Cristina', 'Alberto', 'Lorena', 'Ignacio',
        'Noelia', 'Fernando', 'Susana', 'Ricardo', 'Verónica', 'Rodrigo', 'Miriam', 'Tomás',
        'Nuria', 'Joaquín', 'Eva', 'Enrique', 'Ainhoa', 'Gabriel', 'Julia', 'Héctor',
        'Lidia', 'Raúl',
    ];

    /**
     * Curated the same way: no "Rey", "Campos", "Fuentes" or "Bravo", which
     * a legal text uses as plain words and would restore as a surname.
     */
    protected const LAST_NAMES = [
        'Navarro', 'Ortega', 'Vidal', 'Ibáñez', 'Marín', 'Méndez', 'Núñez', 'Vázquez',
        'Domínguez', 'Benítez', 'Jiménez', 'Gutiérrez', 'Sáez', 'Ferrer', 'Salazar', 'Bermúdez',
        'Figueroa', 'Galindo', 'Maldonado', 'Quiroga', 'Urrutia', 'Tejada', 'Varela', 'Rivas',
        'Montoya', 'Aguirre', 'Echeverría', 'Zabala', 'Arrieta', 'Olmedo', 'Valcárcel', 'Barrientos',
        'Cifuentes', 'Villalobos', 'Mejía', 'Ochoa', 'Sandoval', 'Pacheco', 'Escobar', 'Robledo',
        'Saavedra', 'Herrera', 'Benavides', 'Carmona', 'Velasco', 'Andrade', 'Valdés', 'Uriarte',
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
            'person' => $this->fromPool(self::FIRST_NAMES, $span, $index),
            'surname' => $this->fromPool(self::LAST_NAMES, $span, $index),
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

    /**
     * Consecutive indexes walk consecutive entries of the pool, so the
     * retries of a collision try every name once. Past the end of the pool a
     * typed token takes over, instead of a made-up "Carlos_1".
     *
     * @param array<int, string> $pool
     */
    protected function fromPool(array $pool, Span $span, int $index): string
    {
        $size = count($pool);

        if ($index > $size) {
            return $this->fallbackToken($span, $index);
        }

        return $pool[(crc32($span->type . '|' . $span->value) + $index) % $size];
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
