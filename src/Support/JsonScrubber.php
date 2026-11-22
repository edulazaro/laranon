<?php

namespace EduLazaro\Laranon\Support;

use JsonException;
use stdClass;

/**
 * Anonymizes a JSON document value by value instead of as plain text, so the
 * result is still valid JSON ({"amount":612345678} must not become
 * {"amount":[TEL]}) and escaped text ("José") is seen decoded.
 */
final class JsonScrubber
{
    /**
     * Between two values in the single text $anonymize sees: a paragraph of
     * its own, so no name or number runs from one value into the next, and
     * each value's language is told apart ("phone" is a key in English, the
     * 612345678 next to it a Spanish mobile).
     */
    private const SEPARATOR = "\n\n\x1E\n\n";

    /**
     * Stands in for an integer too large for PHP, which would turn into a
     * float when encoded, until the digits are put back.
     */
    private const BIG_INTEGER = "\u{F8FF}%d\u{F8FF}";

    /**
     * Returns the document re-encoded with every key, every string value and
     * every integer that contains PII passed through $anonymize. They go
     * through it as one text, one value per line, so a name found in one
     * value is also found in the others ("López" in a note after "María
     * López Ruiz" in the client field). Returns null when $text is not a JSON
     * object or array, or when two keys scrub to the same one: the caller
     * then treats it as text.
     *
     * It decodes to objects, not arrays, so {} stays {} and does not come back
     * as [].
     *
     * @param callable(string): string $anonymize
     */
    public static function scrub(string $text, callable $anonymize): ?string
    {
        $start = ltrim($text)[0] ?? '';

        if ($start !== '{' && $start !== '[') {
            return null;
        }

        try {
            $data = json_decode($text, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $plain = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $values = [];
        self::collect($data, $plain, $values);

        foreach ($values as $value) {
            if (str_contains($value, self::SEPARATOR)) {
                return null;
            }
        }

        $scrubbed = $values === [] ? [] : explode(self::SEPARATOR, $anonymize(implode(self::SEPARATOR, $values)));

        if (count($scrubbed) !== count($values)) {
            return null;
        }

        $bigIntegers = [];

        try {
            $result = self::rebuild($data, $plain, $values, $scrubbed, $bigIntegers);
        } catch (JsonException) {
            return null;
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        if ($encoded === false) {
            return null;
        }

        foreach ($bigIntegers as $i => $digits) {
            $encoded = str_replace('"' . sprintf(self::BIG_INTEGER, $i) . '"', $digits, $encoded);
        }

        return $encoded;
    }

    /**
     * Every key, string and integer of the document, in document order, as
     * text. $plain is the same document decoded without JSON_BIGINT_AS_STRING.
     *
     * @param array<int, string> $values
     */
    private static function collect(mixed $value, mixed $plain, array &$values): void
    {
        if (is_string($value) || is_int($value)) {
            $values[] = (string) $value;
        } elseif (is_array($value)) {
            foreach ($value as $key => $item) {
                self::collect($item, $plain[$key], $values);
            }
        } elseif ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $values[] = (string) $key;
                self::collect($item, $plain->{$key}, $values);
            }
        }
    }

    /**
     * The document again, with each collected value swapped for its
     * scrubbed text, walked in the same order as collect(). An integer stays
     * a number unless something was found in it; a string where $plain holds
     * a float was an integer too large for PHP.
     *
     * @param array<int, string> $values
     * @param array<int, string> $scrubbed
     * @param array<int, string> $bigIntegers
     *
     * @throws JsonException when two keys of an object scrub to the same one
     */
    private static function rebuild(mixed $value, mixed $plain, array $values, array $scrubbed, array &$bigIntegers, int &$i = 0): mixed
    {
        if (is_string($value) || is_int($value)) {
            $original = $values[$i];
            $new = $scrubbed[$i++];

            if ($new !== $original || is_string($value) && ! is_float($plain)) {
                return $new;
            }

            if (is_int($value)) {
                return $value;
            }

            $bigIntegers[] = $original;

            return sprintf(self::BIG_INTEGER, count($bigIntegers) - 1);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::rebuild($item, $plain[$key], $values, $scrubbed, $bigIntegers, $i);
            }

            return $value;
        }

        if ($value instanceof stdClass) {
            $object = new stdClass();

            foreach (get_object_vars($value) as $key => $item) {
                $newKey = $scrubbed[$i++];

                if (property_exists($object, $newKey)) {
                    throw new JsonException('Two keys scrub to the same one.');
                }

                $object->{$newKey} = self::rebuild($item, $plain->{$key}, $values, $scrubbed, $bigIntegers, $i);
            }

            return $object;
        }

        return $value;
    }
}
