<?php

namespace EduLazaro\Laranon\Integrations;

use EduLazaro\Laranon\Support\JsonScrubber;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;

/**
 * Registers Http::scrubPii(): a request middleware that redacts PII from the
 * outbound body before it leaves the server. One-way by design; use the
 * anonymize/restore round-trip when the response must be restored. A JSON
 * body is redacted value by value, so it is still valid JSON when it leaves.
 */
class HttpMacro
{
    public static function register(): void
    {
        Http::macro('scrubPii', function () {
            return $this->withRequestMiddleware(function ($request) {
                $body = (string) $request->getBody();

                if ($body === '') {
                    return $request;
                }

                $redact = fn (string $text): string => app('laranon')->strategy('redact')->anonymize($text)->text;
                $scrubbed = JsonScrubber::scrub($body, $redact) ?? $redact($body);

                return $request->withBody(Utils::streamFor($scrubbed));
            });
        });
    }
}
