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
