<?php

namespace EduLazaro\Laranon\Streaming;

use EduLazaro\Laranon\Support\TokenMap;

/**
 * Restores replacements in streamed output (SSE chunks). A replacement can
 * arrive split across chunks, «PER_1» as well as a faker "Javier Rey", even
 * inside a multibyte character. The tail of the buffer is held back while it
 * could still be the start of a replacement, and a whole replacement that
 * ends a word waits for the next character to know whether the word really
 * ends there. Nothing depends on the token delimiters, so it works the same
 * with a custom token_format.
 */
class StreamDeanonymizer
{
    protected string $buffer = '';

    /** The last character already emitted, for the word boundary. */
    protected string $before = '';

    /** @var array<string, true> */
    protected array $holdable = [];

    protected int $longest = 0;

    protected int $builtFor = -1;

    /**
     * $open and $close are no longer used (nothing here depends on the token
     * delimiters) and are kept so 1.x calls still work.
     */
    public function __construct(
        protected TokenMap $map,
        protected string $open = '«',
        protected string $close = '»',
    ) {
    }

    /**
     * Feed a chunk; returns the restored text that is safe to emit now.
     */
    public function push(string $chunk): string
    {
        $this->buffer .= $chunk;

        $hold = $this->holdLength();
        $ready = substr($this->buffer, 0, strlen($this->buffer) - $hold);
        $this->buffer = substr($this->buffer, strlen($ready));

        $incomplete = $this->incompleteTail($this->buffer);
        $after = $incomplete === strlen($this->buffer) ? '' : mb_substr($this->buffer, 0, 1);

        return $this->emit($ready, $after);
    }

    /**
     * Flush whatever remains buffered (call when the stream ends).
     */
    public function flush(): string
    {
        $out = $this->emit($this->buffer, '');
        $this->buffer = '';

        return $out;
    }

    protected function emit(string $raw, string $after): string
    {
        if ($raw === '') {
            return '';
        }

        $out = $this->map->restore($raw, $this->before, $after);
        $this->before = mb_substr($raw, -1);

        return $out;
    }

    /**
     * Bytes to keep: the longest tail that is a proper prefix of some
     * replacement, or a whole replacement still waiting for its word
     * boundary, plus a trailing UTF-8 sequence that is not complete yet.
     */
    protected function holdLength(): int
    {
        $this->buildHoldable();

        $incomplete = $this->incompleteTail($this->buffer);
        $complete = substr($this->buffer, 0, strlen($this->buffer) - $incomplete);

        for ($length = min(strlen($complete), $this->longest); $length > 0; $length--) {
            if (isset($this->holdable[substr($complete, -$length)])) {
                return $length + $incomplete;
            }
        }

        return $incomplete;
    }

    protected function buildHoldable(): void
    {
        $entries = $this->map->entries();

        if ($this->builtFor === count($entries)) {
            return;
        }

        $this->holdable = [];
        $this->longest = 0;

        foreach (array_keys($entries) as $replacement) {
            $replacement = (string) $replacement;
            $size = strlen($replacement);

            for ($i = 1; $i < $size; $i++) {
                $this->holdable[substr($replacement, 0, $i)] = true;
            }

            if (TokenMap::endsWithWordChar($replacement)) {
                $this->holdable[$replacement] = true;
            }

            $this->longest = max($this->longest, $size);
        }

        $this->builtFor = count($entries);
    }

    /**
     * Length of a trailing UTF-8 sequence cut before its last byte.
     */
    protected function incompleteTail(string $text): int
    {
        $length = strlen($text);

        for ($i = 1; $i <= min(3, $length); $i++) {
            $byte = ord($text[$length - $i]);

            if (($byte & 0xC0) === 0x80) {
                continue;
            }

            $needs = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };

            return $needs > $i ? $i : 0;
        }

        return 0;
    }
}
