<?php

namespace EduLazaro\Laranon\Integrations;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;

/**
 * Registers Http::scrubPii(): a request middleware that redacts PII from the
 * outbound body before it leaves the server. One-way by design; use the
 * anonymize/restore round-trip when the response must be restored.
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

                $scrubbed = app('laranon')->strategy('redact')->anonymize($body)->text;

                return $request->withBody(Utils::streamFor($scrubbed));
            });
        });
    }
}
