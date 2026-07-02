<?php

namespace EduLazaro\Laranon\Console;

use EduLazaro\Laranon\Anonymizer;
use Illuminate\Console\Command;

/**
 * Detection audit: shows what would be anonymized in a file. Useful to
 * measure recall against your own corpus before trusting a pipeline.
 */
class ScanCommand extends Command
{
    protected $signature = 'laranon:scan
        {path : File to scan}
        {--types= : Comma-separated list of types to restrict the scan to}';

    protected $description = 'Detect PII in a file and report what would be anonymized';

    public function handle(Anonymizer $anonymizer): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        if ($types = $this->option('types')) {
            $anonymizer = $anonymizer->only(array_map('trim', explode(',', $types)));
        }

        $spans = $anonymizer->scan((string) file_get_contents($path));

        if ($spans === []) {
            $this->info('No PII detected.');

            return self::SUCCESS;
        }

        $this->table(
            ['Type', 'Value', 'Offset', 'Confidence'],
            array_map(
                fn ($span) => [$span->type, $span->value, $span->start, number_format($span->confidence, 2)],
                $spans,
            ),
        );

        $counts = [];

        foreach ($spans as $span) {
            $counts[$span->type] = ($counts[$span->type] ?? 0) + 1;
        }

        ksort($counts);

        $summary = [];

        foreach ($counts as $type => $count) {
            $summary[] = "{$type}={$count}";
        }

        $this->info(count($spans) . ' matches: ' . implode(', ', $summary));

        return self::SUCCESS;
    }
}
