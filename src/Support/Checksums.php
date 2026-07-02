<?php

namespace EduLazaro\Laranon\Support;

/**
 * Checksum validators. These are what separate a real detector from a naive
 * regex: a DNI with the wrong control letter is NOT flagged.
 */
final class Checksums
{
    protected const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    public static function dniLetter(int $number): string
    {
        return self::DNI_LETTERS[$number % 23];
    }

    /**
     * Spanish DNI: 8 digits + mod-23 control letter.
     */
    public static function dni(string $value): bool
    {
        $value = strtoupper(preg_replace('/[\s\-]/', '', $value));

        if (! preg_match('/^(\d{8})([A-Z])$/', $value, $m)) {
            return false;
        }

        return self::dniLetter((int) $m[1]) === $m[2];
    }

    /**
     * Spanish NIE: X/Y/Z + 7 digits + mod-23 control letter, where the
     * leading letter maps to 0/1/2.
     */
    public static function nie(string $value): bool
    {
        $value = strtoupper(preg_replace('/[\s\-]/', '', $value));

        if (! preg_match('/^([XYZ])(\d{7})([A-Z])$/', $value, $m)) {
            return false;
        }

        $number = (int) (strtr($m[1], ['X' => '0', 'Y' => '1', 'Z' => '2']) . $m[2]);

        return self::dniLetter($number) === $m[3];
    }

    /**
     * Spanish CIF: organization letter + 7 digits + control (digit or letter).
     */
    public static function cif(string $value): bool
    {
        $value = strtoupper(preg_replace('/[\s\-]/', '', $value));

        if (! preg_match('/^([ABCDEFGHJKLMNPQRSUVW])(\d{7})([0-9A-J])$/', $value, $m)) {
            return false;
        }

        $sum = 0;

        foreach (str_split($m[2]) as $i => $digit) {
            $digit = (int) $digit;

            if ($i % 2 === 0) {
                $digit *= 2;
                $sum += $digit < 10 ? $digit : $digit - 9;
            } else {
                $sum += $digit;
            }
        }

        $control = (10 - ($sum % 10)) % 10;

        return $m[3] === (string) $control || $m[3] === 'JABCDEFGHI'[$control];
    }

    /**
     * IBAN mod-97 check (ISO 13616). Accepts spaced input.
     */
    public static function iban(string $value): bool
    {
        $value = strtoupper(preg_replace('/\s/', '', $value));

        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $value)) {
            return false;
        }

        $rearranged = substr($value, 4) . substr($value, 0, 4);

        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        return self::mod97($numeric) === 1;
    }

    /**
     * Luhn algorithm (credit cards).
     */
    public static function luhn(string $value): bool
    {
        $value = preg_replace('/[\s\-]/', '', $value);

        if (! ctype_digit($value)) {
            return false;
        }

        $sum = 0;
        $alternate = false;

        for ($i = strlen($value) - 1; $i >= 0; $i--) {
            $digit = (int) $value[$i];

            if ($alternate) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $alternate = ! $alternate;
        }

        return $sum % 10 === 0;
    }

    /**
     * Spanish social security number: 2-digit province + 8-digit number
     * + 2-digit mod-97 control.
     */
    public static function nss(string $value): bool
    {
        $value = preg_replace('/[\s\-\/]/', '', $value);

        if (! preg_match('/^(\d{2})(\d{8})(\d{2})$/', $value, $m)) {
            return false;
        }

        $number = (int) $m[2] < 10000000
            ? ((int) $m[1] * 10000000) + (int) $m[2]
            : (int) ($m[1] . $m[2]);

        return $number % 97 === (int) $m[3];
    }

    /**
     * Spanish legacy bank account (CCC): entity(4) branch(4) control(2)
     * account(10), validated with the standard 11-weight tables.
     */
    public static function ccc(string $value): bool
    {
        $value = preg_replace('/[\s\-]/', '', $value);

        if (! preg_match('/^(\d{8})(\d)(\d)(\d{10})$/', $value, $m)) {
            return false;
        }

        return self::cccDigit($m[1], [4, 8, 5, 10, 9, 7, 3, 6]) === (int) $m[2]
            && self::cccDigit($m[4], [1, 2, 4, 8, 5, 10, 9, 7, 3, 6]) === (int) $m[3];
    }

    protected static function cccDigit(string $digits, array $weights): int
    {
        $sum = 0;

        foreach (str_split($digits) as $i => $digit) {
            $sum += (int) $digit * $weights[$i];
        }

        $control = 11 - ($sum % 11);

        return match ($control) {
            11 => 0,
            10 => 1,
            default => $control,
        };
    }

    /**
     * mod 97 over an arbitrarily long numeric string.
     */
    public static function mod97(string $numeric): int
    {
        $remainder = 0;

        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }
}
