<?php

namespace EduLazaro\Laranon\Support;

use EduLazaro\Laranon\Contracts\Strategy;

/**
 * The match store: replacement ↔ original value pairs plus per-type counters.
 * Stable within a scope: the same value always resolves to the same token.
 */
final class TokenMap
{
    /**
     * Enough retries for a generative strategy to walk its whole name pool
     * (see FakerStrategy) before the suffix fallback kicks in.
     */
    private const MAX_RETRIES = 100;

    private const WORD_CHAR = '[\p{L}\p{N}]';

    /** @var array<string, array<int, array{0: string, 1: bool}>>|null */
    private ?array $heads = null;

    private int $headsBuiltFor = -1;

    /**
     * @param array<string, string> $entries replacement => original (reversible only)
     * @param array<string, string> $index "type|value" => replacement
     * @param array<string, int> $counters type => last index used
     */
    public function __construct(
        protected array $entries = [],
        protected array $index = [],
        protected array $counters = [],
    ) {
    }

    /**
     * Resolve the replacement for a span, reusing the existing token when the
     * same value was already seen under this map. For reversible strategies,
     * a new replacement is never shared with another value, never equal to
     * the value itself, and never a string already present in $context (the
     * text being anonymized): any of the three would restore to the wrong
     * text or leak the original.
     */
    public function replacementFor(Span $span, Strategy $strategy, string $context = ''): string
    {
        $key = $span->type . '|' . $span->value;

        if (isset($this->index[$key])) {
            return $this->index[$key];
        }

        $next = ($this->counters[$span->type] ?? 0) + 1;
        $this->counters[$span->type] = $next;

        $replacement = $strategy->replacement($span, $next);

        if ($strategy->reversible()) {
            $attempt = $next;

            while ($this->unusable($replacement, $span->value, $context) && $attempt < $next + self::MAX_RETRIES) {
                $replacement = $strategy->replacement($span, ++$attempt);
            }

            $base = $replacement;
            $suffix = 1;

            while ($this->unusable($replacement, $span->value, $context)) {
                $replacement = $base . '_' . $suffix++;
            }
        }

        $this->index[$key] = $replacement;

        if ($strategy->reversible() && ! isset($this->entries[$replacement])) {
            $this->entries[$replacement] = $span->value;
        }

        return $replacement;
    }

    protected function unusable(string $replacement, string $value, string $context): bool
    {
        return $replacement === $value
            || $this->isTaken($replacement, $value)
            || ($context !== '' && self::occursIn($replacement, $context));
    }

    /**
     * Whether a replacement is already mapped to a DIFFERENT original value.
     * The same original under another type may share its replacement:
     * restoration is unaffected when the restored text is identical.
     */
    protected function isTaken(string $replacement, string $value): bool
    {
        return isset($this->entries[$replacement]) && $this->entries[$replacement] !== $value;
    }

    /**
     * Pin the replacement for a value to a stable id (e.g. a client's
     * database id) instead of the auto-incrementing counter, reusing the
     * same strategy formatting that organically-detected spans get. A no-op
     * if the value already resolved to something in this map.
     */
    public function preset(string $type, string $value, Strategy $strategy, int $id): void
    {
        $key = $type . '|' . $value;

        if (isset($this->index[$key])) {
            return;
        }

        $replacement = $strategy->replacement(new Span(0, strlen($value), $type, $value), $id);

        $this->index[$key] = $replacement;

        if ($strategy->reversible() && ! isset($this->entries[$replacement])) {
            $this->entries[$replacement] = $value;
        }
    }

    /**
     * Distinct original values already resolved for a given type in this
     * map, regardless of which call established them. Lets later calls in
     * the same scope link a bare mention ("María") to a person only
     * introduced by full name in an earlier turn.
     *
     * @return array<int, string>
     */
    public function valuesForType(string $type): array
    {
        $prefix = $type . '|';
        $values = [];

        foreach (array_keys($this->index) as $key) {
            if (str_starts_with($key, $prefix)) {
                $values[] = substr($key, strlen($prefix));
            }
        }

        return $values;
    }

    /**
     * The type each reversible replacement was issued for.
     *
     * @return array<string, string> replacement => type
     */
    public function replacementTypes(): array
    {
        $types = [];

        foreach ($this->index as $key => $replacement) {
            if (isset($this->entries[$replacement])) {
                $types[(string) $replacement] ??= strstr($key, '|', true);
            }
        }

        return $types;
    }

    /**
     * Replace tokens back with their original values, in a single pass and
     * leftmost-longest, like strtr(). A replacement that starts or ends with
     * a letter or digit only matches as a whole word: "Rey" restores in
     * "Javier Rey firma" but not in "Reyes Católicos".
     *
     * $before and $after are the text right around $text when it is a
     * fragment of a longer one (a streamed chunk): they only decide those
     * word boundaries and are not part of the result.
     */
    public function restore(string $text, string $before = '', string $after = ''): string
    {
        if ($this->entries === [] || $text === '') {
            return $text;
        }

        if (! preg_match_all('/' . self::WORD_CHAR . '+|[^\p{L}\p{N}]/u', $text, $tokens, PREG_OFFSET_CAPTURE)) {
            // Not valid UTF-8: no word boundaries to honour, swap bytes.
            return strtr($text, $this->entries);
        }

        $heads = $this->heads();
        $length = strlen($text);
        $restored = '';
        $cursor = 0;

        foreach ($tokens[0] as [$token, $offset]) {
            if ($offset < $cursor || ! isset($heads[$token])) {
                continue;
            }

            // A word that started in $before: "Rey" inside "MonteRey".
            if ($offset === 0 && self::endsWithWordChar($before) && self::startsWithWordChar($token)) {
                continue;
            }

            foreach ($heads[$token] as [$replacement, $wordTail]) {
                $size = strlen($replacement);

                if ($offset + $size > $length || substr_compare($text, $replacement, $offset, $size) !== 0) {
                    continue;
                }

                $next = $offset + $size;

                if ($wordTail && ($next < $length ? preg_match('/\G' . self::WORD_CHAR . '/u', $text, $m, 0, $next) === 1 : self::startsWithWordChar($after))) {
                    continue;
                }

                $restored .= substr($text, $cursor, $offset - $cursor) . $this->entries[$replacement];
                $cursor = $next;

                break;
            }
        }

        return $restored . substr($text, $cursor);
    }

    /**
     * Replacements grouped by their first word (or first character, when
     * they start with a delimiter like «), longest first. Restoring then
     * costs one lookup per word of the text, however big the map: a single
     * regex alternating every replacement stops compiling at ~2,000 entries.
     *
     * @return array<string, array<int, array{0: string, 1: bool}>>
     */
    private function heads(): array
    {
        if ($this->heads !== null && $this->headsBuiltFor === count($this->entries)) {
            return $this->heads;
        }

        $heads = [];

        foreach (array_keys($this->entries) as $replacement) {
            $replacement = (string) $replacement;

            if (preg_match('/^(?:' . self::WORD_CHAR . '+|.)/us', $replacement, $m)) {
                $heads[$m[0]][] = [$replacement, self::endsWithWordChar($replacement)];
            }
        }

        foreach ($heads as &$candidates) {
            usort($candidates, fn (array $a, array $b) => strlen($b[0]) <=> strlen($a[0]));
        }

        $this->headsBuiltFor = count($this->entries);

        return $this->heads = $heads;
    }

    /**
     * Whether $needle appears in $haystack, as a whole word at the edges
     * that are letters or digits (the same rule restore() applies).
     */
    public static function occursIn(string $needle, string $haystack): bool
    {
        if ($needle === '' || ! str_contains($haystack, $needle)) {
            return false;
        }

        $pattern = (self::startsWithWordChar($needle) ? '(?<!' . self::WORD_CHAR . ')' : '')
            . preg_quote($needle, '/')
            . (self::endsWithWordChar($needle) ? '(?!' . self::WORD_CHAR . ')' : '');

        // An unreadable haystack (invalid UTF-8) counts as a hit: rejecting a
        // candidate is always safe, accepting a clash is not.
        return preg_match('/' . $pattern . '/u', $haystack) !== 0;
    }

    public static function startsWithWordChar(string $text): bool
    {
        return preg_match('/^' . self::WORD_CHAR . '/u', $text) === 1;
    }

    public static function endsWithWordChar(string $text): bool
    {
        return preg_match('/' . self::WORD_CHAR . '$/u', $text) === 1;
    }

    /**
     * @return array<string, string> replacement => original
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [] && $this->index === [];
    }

    public function toArray(): array
    {
        return [
            'entries' => $this->entries,
            'index' => $this->index,
            'counters' => $this->counters,
        ];
    }

    public static function fromArray(?array $payload): self
    {
        return new self(
            $payload['entries'] ?? [],
            $payload['index'] ?? [],
            $payload['counters'] ?? [],
        );
    }
}
