<?php

namespace EduLazaro\Laranon\Integrations;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Monolog processor that redacts PII from log messages and context before
 * anything is written. One-way (redact strategy), no vault involved.
 */
class ScrubLogsProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            $anonymizer = app('laranon')->strategy('redact');

            return $record->with(
                message: $anonymizer->anonymize($record->message)->text,
                context: $this->scrub($record->context, $anonymizer),
            );
        } catch (Throwable) {
            return $record;
        }
    }

    protected function scrub(array $data, object $anonymizer): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $anonymizer->anonymize($value)->text;
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value, $anonymizer);
            }
        }

        return $data;
    }
}
