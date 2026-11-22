<?php

namespace EduLazaro\Laranon\Support;

/**
 * The key under which the TokenMap groups the ways of writing one value, so
 * they all share a token and the model sees one person, not three:
 *
 *   - Name words and known entities ignore case and accents ("García",
 *     "GARCÍA" and "GARCIA" are one surname) but keep ñ and ç, which make
 *     different names (Peña, Pena).
 *   - Identifiers ignore separators and case ("12345678Z", "12.345.678-z").
 *   - Phones keep their digits, without a Spanish country prefix.
 *   - Emails ignore case.
 *
 * Any other type is taken literally.
 */
final class ValueKey
{
    /**
     * Types grouped like names. Their tokens restore to the written form
     * that fits the surrounding text (see TokenMap::restore()).
     */
    public const NAME_TYPES = ['person', 'surname', 'entity'];

    /**
     * Types grouped regardless of separators and case. Their tokens restore
     * to the form seen first.
     */
    public const IDENTIFIER_TYPES = ['dni', 'nie', 'cif', 'vat', 'nss', 'ccc', 'iban', 'credit_card', 'plate', 'ssn', 'nino', 'passport'];

    protected const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
    ];

    protected const HYPHENS = ["\u{2010}" => '-', "\u{2011}" => '-'];

    public static function of(string $type, string $value): string
    {
        return match (true) {
            self::isName($type) => self::withoutAccents(mb_strtolower(strtr($value, self::HYPHENS))),
            in_array($type, self::IDENTIFIER_TYPES, true) => strtoupper(Separators::strip($value, './')),
            $type === 'phone' => self::phone($value),
            $type === 'email' => mb_strtolower($value),
            default => $value,
        };
    }

    /**
     * The digits of a phone, without a Spanish country prefix: "+34 600 123
     * 123", "0034600123123" and "600 12 31 23" are one number.
     */
    protected static function phone(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '34')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    public static function isName(string $type): bool
    {
        return in_array($type, self::NAME_TYPES, true);
    }

    /**
     * "GARCÍA" => "GARCIA", while "Peña" stays "Peña".
     */
    public static function withoutAccents(string $value): string
    {
        return strtr($value, self::ACCENTS);
    }
}
