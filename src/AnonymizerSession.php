<?php

namespace EduLazaro\Laranon;

use EduLazaro\Laranon\Streaming\StreamDeanonymizer;
use EduLazaro\Laranon\Support\TokenMap;

/**
 * A stateful, throwaway anonymization session. It owns ONE in-memory TokenMap
 * and accumulates into it across every call, so all the pieces of a single
 * prompt (user message, retrieved context, tool results) share the same
 * tokens and stay consistent. Nothing is persisted: create it, use it for the
 * turn, let it die with the request.
 *
 *     $anon = Anonymizer::create();
 *     $messages = $anon->anonymize($messages, 'content');   // whole prompt
 *     $args     = $anon->restore($toolCall, 'arguments'); // before running a tool
 *     $reply    = $anon->restore($response);              // before showing it
 *
 * anonymize()/restore() accept:
 *   - a string                      -> returns the transformed string
 *   - a list of strings             -> returns the transformed list
 *   - a list of arrays + key(s)     -> transforms the value at each dot-path
 *     key in every element, in place. Keys support dot notation ('a.b') and
 *     the '*' wildcard ('tool_calls.*.arguments'). Missing keys and
 *     non-string values are skipped; the input shape is returned unchanged
 *     except for the targeted leaves.
 */
class AnonymizerSession
{
    public function __construct(
        protected Anonymizer $engine,
        protected TokenMap $map,
        protected string $tokenOpen = '«',
        protected string $tokenClose = '»',
    ) {
    }

    /**
     * @param string|array $input
     * @param string|array<int, string>|null $keys
     * @return string|array
     */
    public function anonymize(string|array $input, string|array|null $keys = null): string|array
    {
        return $this->transform($input, $keys, fn (string $s): string => $this->engine->anonymizeString($s, $this->map));
    }

    /**
     * @param string|array $input
     * @param string|array<int, string>|null $keys
     * @return string|array
     */
    public function restore(string|array $input, string|array|null $keys = null): string|array
    {
        return $this->transform($input, $keys, fn (string $s): string => $this->map->restore($s));
    }

    /**
     * Streaming-safe restorer bound to this session's map (SSE chunks).
     */
    public function stream(): StreamDeanonymizer
    {
        return new StreamDeanonymizer($this->map, $this->tokenOpen, $this->tokenClose);
    }

    /**
     * The underlying map, in case the caller wants to inspect or persist it.
     */
    public function map(): TokenMap
    {
        return $this->map;
    }

    /**
     * @param string|array $input
     * @param string|array<int, string>|null $keys
     * @return string|array
     */
    protected function transform(string|array $input, string|array|null $keys, callable $cb): string|array
    {
        if (is_string($input)) {
            return $cb($input);
        }

        // List of strings: no keys given.
        if ($keys === null) {
            return array_map(fn ($v) => is_string($v) ? $cb($v) : $v, $input);
        }

        // List of structures: transform the targeted key path(s) in each item.
        $paths = array_map(fn (string $k): array => explode('.', $k), (array) $keys);

        foreach ($input as $i => $item) {
            if (is_array($item)) {
                foreach ($paths as $segments) {
                    $this->applyPath($item, $segments, $cb);
                }
                $input[$i] = $item;
            }
        }

        return $input;
    }

    /**
     * Walk a dot-path (with optional '*' wildcard) into an array and apply the
     * callback to each string leaf it reaches, mutating in place.
     *
     * @param array<int|string, mixed> $item
     * @param array<int, string> $segments
     */
    protected function applyPath(array &$item, array $segments, callable $cb): void
    {
        $segment = $segments[0];
        $rest = array_slice($segments, 1);

        if ($segment === '*') {
            foreach ($item as &$child) {
                if ($rest === []) {
                    if (is_string($child)) {
                        $child = $cb($child);
                    }
                } elseif (is_array($child)) {
                    $this->applyPath($child, $rest, $cb);
                }
            }

            return;
        }

        if (! array_key_exists($segment, $item)) {
            return;
        }

        if ($rest === []) {
            if (is_string($item[$segment])) {
                $item[$segment] = $cb($item[$segment]);
            }

            return;
        }

        if (is_array($item[$segment])) {
            $this->applyPath($item[$segment], $rest, $cb);
        }
    }
}
