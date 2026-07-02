<?php

namespace EduLazaro\Laranon\Recognizers;

use EduLazaro\Laranon\Contracts\Recognizer;
use EduLazaro\Laranon\Support\Span;

/**
 * Base for pattern-driven recognizers: subclasses declare patterns with a
 * confidence each, plus an optional checksum validation. When a pattern has
 * a capturing group, the first group is used as the span (so context words
 * used to gate a match are not swallowed into it).
 */
abstract class RegexRecognizer implements Recognizer
{
    abstract public function type(): string;

    /**
     * @return array<string, float> pattern => confidence
     */
    abstract protected function patterns(): array;

    /**
     * Checksum/format validation over the raw matched value.
     */
    protected function validate(string $value): bool
    {
        return true;
    }

    public function detect(string $text): array
    {
        $spans = [];

        foreach ($this->patterns() as $pattern => $confidence) {
            if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $i => $full) {
                [$value, $offset] = isset($matches[1][$i]) && $matches[1][$i][1] !== -1
                    ? $matches[1][$i]
                    : $full;

                if ($value === '' || ! $this->validate($value)) {
                    continue;
                }

                $spans[] = new Span($offset, strlen($value), $this->type(), $value, $confidence);
            }
        }

        return $spans;
    }
}
