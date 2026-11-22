<?php

namespace EduLazaro\Laranon\Integrations;

use EduLazaro\Laranon\Support\JsonScrubber;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Monolog processor that redacts PII from log messages and context before
 * anything is written. One-way (redact strategy), no vault involved. A
 * message or context string holding JSON is redacted value by value.
 */
class ScrubLogsProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            $anonymizer = app('laranon')->strategy('redact');

            return $record->with(
                message: $this->redact($record->message, $anonymizer),
                context: $this->scrub($record->context, $anonymizer),
            );
        } catch (Throwable) {
            return $record;
        }
    }

    protected function redact(string $text, object $anonymizer): string
    {
        $redact = fn (string $value): string => $anonymizer->anonymize($value)->text;

        return JsonScrubber::scrub($text, $redact) ?? $redact($text);
    }

    protected function scrub(array $data, object $anonymizer): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->redact($value, $anonymizer);
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value, $anonymizer);
            }
        }

        return $data;
    }
}
