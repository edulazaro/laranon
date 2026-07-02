<?php

namespace EduLazaro\Laranon\Integrations;

use Illuminate\Log\Logger;

/**
 * Logging channel tap. Add it in config/logging.php:
 *
 *     'stack' => [
 *         'driver' => 'stack',
 *         'tap' => [\EduLazaro\Laranon\Integrations\ScrubLogsTap::class],
 *         ...
 *     ],
 */
class ScrubLogsTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new ScrubLogsProcessor());
    }
}
