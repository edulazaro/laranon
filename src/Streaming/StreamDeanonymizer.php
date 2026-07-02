<?php

namespace EduLazaro\Laranon\Streaming;

use EduLazaro\Laranon\Support\TokenMap;

/**
 * Restores tokens in streamed output (SSE chunks). A token like «PER_1» can
 * arrive split across two chunks, or even split inside the multibyte «
 * delimiter: partial candidates are buffered and emitted once complete.
 */
class StreamDeanonymizer
{
    protected const MAX_TOKEN_BYTES = 256;

    protected string $buffer = '';

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

        $openPos = strrpos($this->buffer, $this->open);

        $holdingToken = $openPos !== false
            && strpos($this->buffer, $this->close, $openPos) === false
            && (strlen($this->buffer) - $openPos) <= self::MAX_TOKEN_BYTES;

        if ($holdingToken) {
            $emit = substr($this->buffer, 0, $openPos);
            $this->buffer = substr($this->buffer, $openPos);
        } else {
            $emit = $this->buffer;
            $this->buffer = '';
        }

        // Hold back a trailing partial byte-prefix of the opening delimiter
        // (e.g. the first byte of a multibyte «) so it is never emitted split.
        for ($i = strlen($this->open) - 1; $i > 0; $i--) {
            $prefix = substr($this->open, 0, $i);

            if (str_ends_with($emit, $prefix)) {
                $emit = substr($emit, 0, -strlen($prefix));
                $this->buffer = $prefix . $this->buffer;
                break;
            }
        }

        return $this->map->restore($emit);
    }

    /**
     * Flush whatever remains buffered (call when the stream ends).
     */
    public function flush(): string
    {
        $out = $this->map->restore($this->buffer);
        $this->buffer = '';

        return $out;
    }
}
