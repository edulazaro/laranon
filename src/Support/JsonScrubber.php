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
     * Returns the document re-encoded with every key, every string value and
     * every integer that contains PII passed through $anonymize. Returns null
     * when $text is not a JSON object or array, or when it cannot come back
     * faithfully: an integer too large for PHP, which would turn into a
     * float, or two keys that scrub to the same one. The caller then treats
     * it as text.
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
            $scrubbed = self::walk($data, $anonymize, json_decode($text, false, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return null;
        }

        $encoded = json_encode($scrubbed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return $encoded === false ? null : $encoded;
    }

    /**
     * $plain is the same value decoded without JSON_BIGINT_AS_STRING: where
     * $value holds a string and $plain a float, the document had an integer
     * PHP cannot hold.
     *
     * @param callable(string): string $anonymize
     *
     * @throws JsonException when the document cannot be re-encoded faithfully
     */
    private static function walk(mixed $value, callable $anonymize, mixed $plain): mixed
    {
        if (is_string($value)) {
            if (is_float($plain)) {
                throw new JsonException('An integer too large for PHP.');
            }

            return $anonymize($value);
        }

        if (is_int($value)) {
            // A phone or an id sent as a number is PII too. It becomes the
            // redacted text only when something was found in it.
            $digits = (string) $value;
            $scrubbed = $anonymize($digits);

            return $scrubbed === $digits ? $value : $scrubbed;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::walk($item, $anonymize, $plain[$key]);
            }

            return $value;
        }

        if ($value instanceof stdClass) {
            $scrubbed = new stdClass();

            foreach (get_object_vars($value) as $key => $item) {
                $newKey = $anonymize((string) $key);

                if (property_exists($scrubbed, $newKey)) {
                    throw new JsonException('Two keys scrub to the same one.');
                }

                $scrubbed->{$newKey} = self::walk($item, $anonymize, $plain->{$key});
            }

            return $scrubbed;
        }

        return $value;
    }
}
