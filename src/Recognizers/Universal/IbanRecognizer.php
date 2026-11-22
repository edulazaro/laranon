<?php

namespace EduLazaro\Laranon\Recognizers\Universal;

use EduLazaro\Laranon\Recognizers\RegexRecognizer;
use EduLazaro\Laranon\Support\Checksums;
use EduLazaro\Laranon\Support\Separators;
use EduLazaro\Laranon\Support\Span;

class IbanRecognizer extends RegexRecognizer
{
    public function type(): string
    {
        return 'iban';
    }

    /**
     * The pattern is greedy, so an uppercase word or a number right after the
     * IBAN ("1332 DEL TITULAR"), or the next IBAN, is absorbed as more groups
     * and breaks the checksum. When a match fails, its trailing groups are
     * dropped one by one until a prefix passes mod-97, and the search goes on
     * right after that prefix, so an IBAN that follows is still found.
     */
    public function detect(string $text): array
    {
        $spans = [];

        foreach ($this->patterns() as $pattern => $confidence) {
            $position = 0;

            while (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $position) === 1) {
                [$value, $offset] = $match[0];

                while (! $this->validate($value) && preg_match_all('/' . Separators::ONE . '/', $value, $separators, PREG_OFFSET_CAPTURE)) {
                    $value = substr($value, 0, end($separators[0])[1]);
                }

                if ($this->validate($value)) {
                    $spans[] = new Span($offset, strlen($value), $this->type(), $value, $confidence);
                    $position = $offset + strlen($value);
                } else {
                    $position = $offset + 1;
                }
            }
        }

        return $spans;
    }

    protected function patterns(): array
    {
        // Case-insensitive: "es91 2100…" is still an IBAN, and mod-97 keeps
        // lowercase codes that only look like one out. Groups are split by a
        // space or a dash, as with any other identifier.
        return [
            '/\b[A-Z]{2}\d{2}(?:' . Separators::ONE . '?[A-Z0-9]{2,4}){3,8}\b/i' => 1.0,
        ];
    }

    protected function validate(string $value): bool
    {
        $normalized = Separators::strip($value);

        return strlen($normalized) >= 15
            && strlen($normalized) <= 34
            && Checksums::iban($normalized);
    }
}
