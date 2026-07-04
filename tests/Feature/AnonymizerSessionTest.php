<?php

namespace EduLazaro\Laranon\Tests\Feature;

use EduLazaro\Laranon\Anonymizer;
use EduLazaro\Laranon\Tests\TestCase;

class AnonymizerSessionTest extends TestCase
{
    public function test_a_string_round_trips(): void
    {
        $anon = Anonymizer::create();

        $text = $anon->anonymize('El DNI de María López es 12345678Z.');

        $this->assertStringNotContainsString('María', $text);
        $this->assertStringNotContainsString('12345678Z', $text);
        $this->assertSame('El DNI de María López es 12345678Z.', $anon->deanonymize($text));
    }

    public function test_one_map_is_shared_across_calls(): void
    {
        $anon = Anonymizer::create();

        // "María" first appears here...
        $a = $anon->anonymize('Comparece Juan Pérez y María López.');
        // ...and alone in a later, separate call: must reuse the SAME token.
        $b = $anon->anonymize('María confirmó los datos.');

        // Juan=PER_1, María=PER_2 in the first string; the second reuses PER_2.
        $this->assertStringContainsString('«PER_2»', $a);
        $this->assertStringContainsString('«PER_2»', $b);

        $this->assertSame('María confirmó los datos.', $anon->deanonymize($b));
    }

    public function test_list_of_strings(): void
    {
        $anon = Anonymizer::create();

        [$a, $b] = $anon->anonymize(['DNI 12345678Z', 'correo maria@example.com']);

        $this->assertStringNotContainsString('12345678Z', $a);
        $this->assertStringNotContainsString('maria@example.com', $b);
        $this->assertSame('DNI 12345678Z', $anon->deanonymize($a));
        $this->assertSame('correo maria@example.com', $anon->deanonymize($b));
    }

    public function test_structure_with_a_single_key(): void
    {
        $anon = Anonymizer::create();

        $messages = [
            ['role' => 'system', 'content' => 'Eres el asistente del caso.'],
            ['role' => 'user', 'content' => '¿Cuánto reclama María López?'],
        ];

        $out = $anon->anonymize($messages, 'content');

        // role is never touched; content is anonymized.
        $this->assertSame('system', $out[0]['role']);
        $this->assertStringNotContainsString('María', $out[1]['content']);

        // Reverse the assistant reply built on the same session.
        $reply = '«PER_1» «AP_1» reclama 4.000 €.';
        $this->assertSame('María López reclama 4.000 €.', $anon->deanonymize($reply));
    }

    public function test_nested_dot_key(): void
    {
        $anon = Anonymizer::create();

        $items = [['payload' => ['text' => 'Titular: María López']]];

        $out = $anon->anonymize($items, 'payload.text');

        $this->assertStringNotContainsString('María', $out[0]['payload']['text']);
        $this->assertSame('Titular: María López', $anon->deanonymize($out[0]['payload']['text']));
    }

    public function test_wildcard_key_covers_tool_call_arguments(): void
    {
        $anon = Anonymizer::create();

        $messages = [[
            'role' => 'assistant',
            'tool_calls' => [
                ['name' => 'buscar', 'arguments' => 'nombre: María López'],
                ['name' => 'buscar', 'arguments' => 'dni: 12345678Z'],
            ],
        ]];

        $out = $anon->anonymize($messages, 'tool_calls.*.arguments');

        $this->assertStringNotContainsString('María', $out[0]['tool_calls'][0]['arguments']);
        $this->assertStringNotContainsString('12345678Z', $out[0]['tool_calls'][1]['arguments']);
        // Tool names are left alone.
        $this->assertSame('buscar', $out[0]['tool_calls'][0]['name']);

        // Deanonymize the args symmetrically before running the tool.
        $back = $anon->deanonymize($out, 'tool_calls.*.arguments');
        $this->assertSame('nombre: María López', $back[0]['tool_calls'][0]['arguments']);
        $this->assertSame('dni: 12345678Z', $back[0]['tool_calls'][1]['arguments']);
    }

    public function test_multiple_keys_and_missing_keys(): void
    {
        $anon = Anonymizer::create();

        $items = [
            ['content' => 'María López', 'extra' => 'DNI 12345678Z'],
            ['content' => 'Juan Ruiz'], // sin 'extra': no debe romper
        ];

        $out = $anon->anonymize($items, ['content', 'extra']);

        $this->assertStringNotContainsString('María', $out[0]['content']);
        $this->assertStringNotContainsString('12345678Z', $out[0]['extra']);
        $this->assertStringNotContainsString('Juan', $out[1]['content']);
        $this->assertArrayNotHasKey('extra', $out[1]);
    }

    public function test_full_chat_turn_flow(): void
    {
        $anon = Anonymizer::create();

        // 1. Prompt: anonymize the content of every message with one session.
        $messages = [
            ['role' => 'system', 'content' => 'Asistente del caso.'],
            ['role' => 'user', 'content' => '¿Cuánto reclama María López y su DNI?'],
        ];
        $messages = $anon->anonymize($messages, 'content');
        $this->assertStringNotContainsString('María', $messages[1]['content']);

        // 2. Tool result (real PII from DB) added and anonymized with the same map.
        $toolResult = ['nombre' => 'María López', 'dni' => '12345678Z', 'importe' => 4000];
        $messages[] = ['role' => 'tool', 'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE)];
        $messages = $anon->anonymize($messages, 'content');
        $this->assertStringNotContainsString('12345678Z', $messages[2]['content']);

        // 3. Model reply (tokens only) -> deanonymize for the user.
        $reply = '«PER_1» «AP_1» reclama 4.000 € y su DNI es «DNI_1».';
        $this->assertSame(
            'María López reclama 4.000 € y su DNI es 12345678Z.',
            $anon->deanonymize($reply),
        );
    }
}
