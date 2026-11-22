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

    public function test_faker_surrogates_split_at_any_point_are_restored(): void
    {
        $map = new TokenMap(['Javier' => 'María', 'Rey' => 'López', 'Navarro' => 'Ibáñez']);

        $full = 'La actora Javier Rey reclama; Navarro firma, y Reyes no es nadie. Fin: Rey';
        $expected = 'La actora María López reclama; Ibáñez firma, y Reyes no es nadie. Fin: López';

        for ($cut = 1; $cut < strlen($full); $cut++) {
            $stream = new StreamDeanonymizer($map);
            $out = $stream->push(substr($full, 0, $cut)) . $stream->push(substr($full, $cut)) . $stream->flush();

            $this->assertSame($expected, $out, "Failed when cut at byte {$cut}");
        }

        $stream = new StreamDeanonymizer($map);
        $out = '';

        foreach (str_split($full) as $byte) {
            $out .= $stream->push($byte);
        }

        $this->assertSame($expected, $out . $stream->flush());
    }

    public function test_a_word_is_not_restored_when_it_continues_in_the_next_chunk(): void
    {
        $stream = new StreamDeanonymizer(new TokenMap(['Rey' => 'López']));

        $out = $stream->push('Los Rey') . $stream->push('es Católicos') . $stream->flush();

        $this->assertSame('Los Reyes Católicos', $out);
    }

    public function test_a_custom_token_format_streams_without_guillemets(): void
    {
        $map = new TokenMap(['[[PER_1]]' => 'Ana', '[[PER_12]]' => 'Eva']);
        $full = 'Firma [[PER_12]] ante [[PER_1]].';

        for ($cut = 1; $cut < strlen($full); $cut++) {
            $stream = new StreamDeanonymizer($map);
            $out = $stream->push(substr($full, 0, $cut)) . $stream->push(substr($full, $cut)) . $stream->flush();

            $this->assertSame('Firma Eva ante Ana.', $out, "Failed when cut at byte {$cut}");
        }
    }

    public function test_a_name_with_several_forms_streams_like_the_whole_text(): void
    {
        $map = new TokenMap(
            ['«PER_1»' => 'JUAN', '«AP_1»' => 'GARCÍA'],
            ['person|juan' => '«PER_1»', 'surname|garcia' => '«AP_1»'],
            ['person' => 1, 'surname' => 1],
            ['person|juan' => ['JUAN', 'Juan'], 'surname|garcia' => ['GARCÍA', 'García']],
        );

        $text = "PARTE: «PER_1» «AP_1»\nEl demandante «PER_1» «AP_1» reclama.";
        $expected = "PARTE: JUAN GARCÍA\nEl demandante Juan García reclama.";

        $this->assertSame($expected, $map->restore($text));

        // Every pair of cut points, the multibyte « included.
        for ($i = 0; $i <= strlen($text); $i++) {
            for ($j = $i; $j <= strlen($text); $j++) {
                $stream = new StreamDeanonymizer($map);
                $out = $stream->push(substr($text, 0, $i)) . $stream->push(substr($text, $i, $j - $i))
                    . $stream->push(substr($text, $j)) . $stream->flush();

                $this->assertSame($expected, $out, "cut at {$i}/{$j}");
            }
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
