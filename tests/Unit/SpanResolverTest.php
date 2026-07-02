<?php

namespace EduLazaro\Laranon\Tests\Unit;

use EduLazaro\Laranon\Support\Span;
use EduLazaro\Laranon\Support\SpanResolver;
use PHPUnit\Framework\TestCase;

class SpanResolverTest extends TestCase
{
    public function test_longest_match_wins_on_overlap(): void
    {
        $short = new Span(0, 5, 'a', 'short');
        $long = new Span(2, 10, 'b', 'longermatch');

        $resolved = SpanResolver::resolve([$short, $long]);

        $this->assertCount(1, $resolved);
        $this->assertSame('b', $resolved[0]->type);
    }

    public function test_non_overlapping_spans_are_kept_and_ordered(): void
    {
        $second = new Span(20, 5, 'a', 'two');
        $first = new Span(0, 5, 'b', 'one');

        $resolved = SpanResolver::resolve([$second, $first]);

        $this->assertCount(2, $resolved);
        $this->assertSame('b', $resolved[0]->type);
        $this->assertSame('a', $resolved[1]->type);
    }

    public function test_confidence_breaks_length_ties(): void
    {
        $low = new Span(0, 5, 'low', 'aaaaa', 0.5);
        $high = new Span(0, 5, 'high', 'aaaaa', 0.9);

        $resolved = SpanResolver::resolve([$low, $high]);

        $this->assertCount(1, $resolved);
        $this->assertSame('high', $resolved[0]->type);
    }
}
