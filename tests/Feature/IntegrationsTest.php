<?php

namespace EduLazaro\Laranon\Tests\Feature;

use DateTimeImmutable;
use EduLazaro\Laranon\Integrations\ScrubLogsProcessor;
use EduLazaro\Laranon\Support\JsonScrubber;
use EduLazaro\Laranon\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use stdClass;

class IntegrationsTest extends TestCase
{
    public function test_scrub_pii_keeps_a_json_body_valid(): void
    {
        // The fake's callback runs after the request middleware, so it sees
        // the body that actually leaves.
        $sent = null;

        Http::fake(function (Request $request) use (&$sent) {
            $sent = $request->body();

            return Http::response('ok');
        });

        Http::scrubPii()->post('https://api.example.com/claims', [
            'phone' => 612345678,
            'amount' => 4000,
            'name' => 'José García',
            'options' => new stdClass(),
            'tags' => [],
        ]);

        $data = json_decode($sent);

        $this->assertNotNull($data, (string) $sent);
        $this->assertSame('[TEL]', $data->phone);
        $this->assertSame(4000, $data->amount);
        $this->assertSame('[PER] [AP]', $data->name);
        $this->assertStringContainsString('"options":{}', $sent);
        $this->assertStringContainsString('"tags":[]', $sent);
    }

    public function test_scrub_pii_still_redacts_a_plain_text_body(): void
    {
        $sent = null;

        Http::fake(function (Request $request) use (&$sent) {
            $sent = $request->body();

            return Http::response('ok');
        });

        Http::scrubPii()->withBody('Llamar al 612345678', 'text/plain')->post('https://api.example.com/notes');

        $this->assertSame('Llamar al [TEL]', $sent);
    }

    public function test_json_scrubbing_covers_keys_and_never_changes_a_number(): void
    {
        $redact = fn (string $text) => $this->app->make('laranon')->strategy('redact')->anonymize($text)->text;

        // A key is scrubbed like a value.
        $this->assertSame(
            '{"[EMAIL]":{"name":"[PER] [AP]","phone":"[TEL]"}}',
            JsonScrubber::scrub('{"juan.garcia@example.com":{"name":"Juan García","phone":612345678}}', $redact),
        );

        // A name found in one value is found in the others.
        $this->assertSame(
            '{"cliente":"[PER] [AP] [AP]","nota":"[AP] no contesta; hablar con [AP]"}',
            JsonScrubber::scrub('{"cliente":"María López Ruiz","nota":"López no contesta; hablar con Ruiz"}', $redact),
        );

        // An integer too large for PHP comes back as it was, or scrubbed.
        $this->assertSame(
            '{"ccc":"[CCC]","id":18446744073709551615}',
            JsonScrubber::scrub('{"ccc":21000418450200051332,"id":18446744073709551615}', $redact),
        );

        // Two keys that would scrub to the same one: the caller treats it as text.
        $this->assertNull(JsonScrubber::scrub('{"ana@example.com":1,"luis@example.com":2}', $redact));
    }

    public function test_log_processor_redacts_json_strings_value_by_value(): void
    {
        $record = new LogRecord(
            new DateTimeImmutable(),
            'app',
            Level::Info,
            json_encode(['name' => 'José García']),
            [
                'payload' => json_encode(['phone' => 612345678, 'dni' => '12345678Z']),
                'note' => 'Llamar al 612345678',
            ],
        );

        $scrubbed = (new ScrubLogsProcessor())($record);

        $this->assertSame('[PER] [AP]', json_decode($scrubbed->message)->name);
        $this->assertSame('[TEL]', json_decode($scrubbed->context['payload'])->phone);
        $this->assertSame('[DNI]', json_decode($scrubbed->context['payload'])->dni);
        $this->assertSame('Llamar al [TEL]', $scrubbed->context['note']);
    }
}
