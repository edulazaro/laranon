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
     * IBAN ("1332 DEL TITULAR") is absorbed as one more group and breaks the
     * checksum, which would leave the whole IBAN in cleartext. When a match
     * fails, its trailing groups are dropped one by one until a prefix passes
     * mod-97.
     */
    public function detect(string $text): array
    {
        $spans = [];

        foreach ($this->patterns() as $pattern => $confidence) {
            if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$value, $offset]) {
                while (! $this->validate($value)) {
                    if (! preg_match('/^(.*\S)\s+\S+$/su', $value, $m)) {
                        continue 2;
                    }

                    $value = $m[1];
                }

                $spans[] = new Span($offset, strlen($value), $this->type(), $value, $confidence);
            }
        }

        return $spans;
    }

    protected function patterns(): array
    {
        return [
            '/\b[A-Z]{2}\d{2}(?:' . Separators::SPACE . '?[A-Z0-9]{2,4}){3,8}\b/' => 1.0,
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
