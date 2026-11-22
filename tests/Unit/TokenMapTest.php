<?php

namespace EduLazaro\Laranon\Tests\Unit;

use EduLazaro\Laranon\Contracts\Strategy;
use EduLazaro\Laranon\Strategies\TokenStrategy;
use EduLazaro\Laranon\Support\Span;
use EduLazaro\Laranon\Support\TokenMap;
use PHPUnit\Framework\TestCase;

class TokenMapTest extends TestCase
{
    public function test_same_value_reuses_the_same_token(): void
    {
        $map = new TokenMap();
        $strategy = new TokenStrategy('«%s_%d»', ['dni' => 'DNI']);

        $a = $map->replacementFor(new Span(0, 9, 'dni', '12345678Z'), $strategy);
        $b = $map->replacementFor(new Span(50, 9, 'dni', '12345678Z'), $strategy);
        $c = $map->replacementFor(new Span(90, 9, 'dni', '87654321X'), $strategy);

        $this->assertSame('«DNI_1»', $a);
        $this->assertSame('«DNI_1»', $b);
        $this->assertSame('«DNI_2»', $c);
    }

    public function test_restore_replaces_tokens_with_originals(): void
    {
        $map = new TokenMap(['«DNI_1»' => '12345678Z']);

        $this->assertSame(
            'El DNI 12345678Z consta en autos.',
            $map->restore('El DNI «DNI_1» consta en autos.'),
        );
    }

    public function test_restore_only_matches_whole_words(): void
    {
        $map = new TokenMap(['Rey' => 'López', 'Javier' => 'María']);

        $this->assertSame('María López firma.', $map->restore('Javier Rey firma.'));
        $this->assertSame('Reyes Católicos', $map->restore('Reyes Católicos'));
        $this->assertSame('Monterrey y MonteRey', $map->restore('Monterrey y MonteRey'));
        $this->assertSame('«López»', $map->restore('«Rey»'));
    }

    public function test_restore_is_single_pass_and_longest_first(): void
    {
        // "Vidal" restores to a real "Rey", which must not be restored again.
        $map = new TokenMap(['Vidal' => 'Rey', 'Rey' => 'López', '«PER_1»' => 'Ana', '«PER_12»' => 'Eva']);

        $this->assertSame('Rey y López', $map->restore('Vidal y Rey'));
        $this->assertSame('Eva y Ana', $map->restore('«PER_12» y «PER_1»'));
    }

    public function test_restore_handles_maps_too_big_for_one_regex(): void
    {
        $entries = [];

        for ($i = 1; $i <= 5000; $i++) {
            $entries["«PER_{$i}»"] = "Persona{$i}";
            $entries["Sustituto{$i}"] = "Original{$i}";
        }

        $map = new TokenMap($entries);

        $this->assertSame('Persona4999 y Original77.', $map->restore('«PER_4999» y Sustituto77.'));
    }

    public function test_a_replacement_already_in_the_text_is_never_issued(): void
    {
        $always = new class implements Strategy
        {
            public function replacement(Span $span, int $index): string
            {
                return $index === 1 ? 'Rey' : 'Vidal';
            }

            public function reversible(): bool
            {
                return true;
            }
        };

        $map = new TokenMap();

        $this->assertSame('Vidal', $map->replacementFor(new Span(0, 5, 'surname', 'López'), $always, 'López. Lo firma el Rey.'));
    }

    public function test_a_pinned_id_taken_by_another_value_gets_a_regular_token(): void
    {
        $strategy = new TokenStrategy('«%s_%d»', ['entity' => 'ENT']);
        $map = new TokenMap();

        $this->assertSame('«ENT_1»', $map->replacementFor(new Span(0, 11, 'entity', 'Globex Corp'), $strategy));

        $map->preset('entity', 'Initech SL', $strategy, 1);

        $this->assertSame('«ENT_2»', $map->replacementFor(new Span(0, 10, 'entity', 'Initech SL'), $strategy));
        $this->assertSame('Globex Corp, Initech SL', $map->restore('«ENT_1», «ENT_2»'));
    }

    public function test_replacements_never_repeat_across_different_values(): void
    {
        // A degenerate strategy that always emits the same surrogate: the map
        // must still guarantee one replacement per distinct value.
        $degenerate = new class implements Strategy
        {
            public function replacement(Span $span, int $index): string
            {
                return 'X';
            }

            public function reversible(): bool
            {
                return true;
            }
        };

        $map = new TokenMap();

        $a = $map->replacementFor(new Span(0, 3, 'person', 'Ana'), $degenerate);
        $b = $map->replacementFor(new Span(10, 3, 'person', 'Eva'), $degenerate);

        $this->assertSame('X', $a);
        $this->assertNotSame($a, $b);
        $this->assertSame('Ana Eva', $map->restore($a . ' ' . $b));
    }

    public function test_round_trips_through_array_payload(): void
    {
        $map = new TokenMap();
        $strategy = new TokenStrategy();

        $map->replacementFor(new Span(0, 9, 'dni', '12345678Z'), $strategy);

        $rehydrated = TokenMap::fromArray($map->toArray());

        $this->assertSame(
            $map->replacementFor(new Span(10, 9, 'dni', '12345678Z'), $strategy),
            $rehydrated->replacementFor(new Span(10, 9, 'dni', '12345678Z'), $strategy),
        );
    }
}
