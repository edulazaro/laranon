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
     * replacements are guaranteed unique across different values: a shared
     * surrogate would merge two people and garble restoration.
     */
    public function replacementFor(Span $span, Strategy $strategy): string
    {
        $key = $span->type . '|' . $span->value;

        if (isset($this->index[$key])) {
            return $this->index[$key];
        }

        $next = ($this->counters[$span->type] ?? 0) + 1;
        $this->counters[$span->type] = $next;

        $replacement = $strategy->replacement($span, $next);

        if ($strategy->reversible()) {
            // Counter-based strategies never collide; generative ones
            // (faker) can. Retry with bumped indexes, then force a suffix.
            $attempt = $next;

            while ($this->isTaken($replacement, $span->value) && $attempt < $next + 25) {
                $replacement = $strategy->replacement($span, ++$attempt);
            }

            $base = $replacement;
            $suffix = 1;

            while ($this->isTaken($replacement, $span->value)) {
                $replacement = $base . '_' . $suffix++;
            }
        }

        $this->index[$key] = $replacement;

        if ($strategy->reversible() && ! isset($this->entries[$replacement])) {
            $this->entries[$replacement] = $span->value;
        }

        return $replacement;
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
     * Replace tokens back with their original values.
     */
    public function restore(string $text): string
    {
        if ($this->entries === []) {
            return $text;
        }

        return strtr($text, $this->entries);
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
