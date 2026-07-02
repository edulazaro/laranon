<?php

namespace EduLazaro\Laranon\Tests\Unit;

use EduLazaro\Laranon\Streaming\StreamDeanonymizer;
use EduLazaro\Laranon\Support\TokenMap;
use PHPUnit\Framework\TestCase;

class StreamDeanonymizerTest extends TestCase
{
    public function test_tokens_split_across_chunks_are_restored(): void
    {
        $map = new TokenMap([
            '«PER_1»' => 'María García',
            '«DNI_1»' => '12345678Z',
        ]);

        $full = 'Estimada «PER_1», su DNI «DNI_1» consta en el expediente.';

        // Feed in tiny chunks so tokens (and the multibyte « itself) split.
        foreach ([1, 2, 3, 5, 7] as $size) {
            $stream = new StreamDeanonymizer($map);
            $out = '';

            foreach (str_split($full, $size) as $chunk) {
                $out .= $stream->push($chunk);
            }

            $out .= $stream->flush();

            $this->assertSame(
                'Estimada María García, su DNI 12345678Z consta en el expediente.',
                $out,
                "Failed with chunk size {$size}",
            );
        }
    }

    public function test_lone_open_delimiter_does_not_stall_the_stream(): void
    {
        $stream = new StreamDeanonymizer(new TokenMap(['«PER_1»' => 'Ana']));

        $out = $stream->push('El símbolo « aparece suelto ');
        $out .= $stream->push(str_repeat('y el texto sigue fluyendo. ', 20));
        $out .= $stream->flush();

        $this->assertStringContainsString('aparece suelto', $out);
        $this->assertStringContainsString('sigue fluyendo', $out);
    }
}
