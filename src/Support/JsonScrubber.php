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
     * Returns the document re-encoded with every string value, and every
     * integer that contains PII, passed through $anonymize. Returns null when
     * $text is not a JSON object or array, so the caller treats it as text.
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
            $data = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $encoded = json_encode(self::walk($data, $anonymize), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @param callable(string): string $anonymize
     */
    private static function walk(mixed $value, callable $anonymize): mixed
    {
        if (is_string($value)) {
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
                $value[$key] = self::walk($item, $anonymize);
            }

            return $value;
        }

        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = self::walk($item, $anonymize);
            }
        }

        return $value;
    }
}
