<?php

namespace EduLazaro\Laranon\Support;

/**
 * The key under which the TokenMap groups the ways of writing one value, so
 * they all share a token and the model sees one person, not three. Name
 * words and known entities ignore case and accents ("García", "GARCÍA" and
 * "GARCIA" are one surname) but keep ñ and ç, which make different names
 * (Peña, Pena). Any other type is taken literally.
 */
final class ValueKey
{
    /**
     * Types grouped like names. Their tokens restore to the written form
     * that fits the surrounding text (see TokenMap::restore()).
     */
    public const NAME_TYPES = ['person', 'surname', 'entity'];

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
        if (self::isName($type)) {
            return self::withoutAccents(mb_strtolower(strtr($value, self::HYPHENS)));
        }

        return $value;
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
