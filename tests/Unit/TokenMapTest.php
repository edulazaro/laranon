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

    public function test_ways_of_writing_a_name_share_a_token_but_n_tilde_does_not_fold(): void
    {
        $map = new TokenMap();
        $strategy = new TokenStrategy('«%s_%d»', ['surname' => 'AP']);

        $tokens = array_map(
            fn (string $value) => $map->replacementFor(new Span(0, strlen($value), 'surname', $value), $strategy),
            ['García', 'GARCÍA', 'GARCIA', 'Peña', 'PENA'],
        );

        $this->assertSame(['«AP_1»', '«AP_1»', '«AP_1»', '«AP_2»', '«AP_3»'], $tokens);
        $this->assertSame(['García', 'GARCÍA', 'GARCIA', 'Peña', 'PENA'], $map->valuesForType('surname'));
    }

    public function test_identifiers_written_in_several_ways_share_a_token(): void
    {
        $map = new TokenMap();
        $strategy = new TokenStrategy('«%s_%d»', ['dni' => 'DNI', 'phone' => 'TEL', 'email' => 'EMAIL', 'iban' => 'IBAN']);
        $token = fn (string $type, string $value) => $map->replacementFor(new Span(0, strlen($value), $type, $value), $strategy);

        $this->assertSame('«DNI_1»', $token('dni', '12345678Z'));
        $this->assertSame('«DNI_1»', $token('dni', '12.345.678-z'));
        $this->assertSame('«TEL_1»', $token('phone', '+34 600 123 123'));
        $this->assertSame('«TEL_1»', $token('phone', '600123123'));
        $this->assertSame('«TEL_1»', $token('phone', '0034 600 12 31 23'));
        $this->assertSame('«TEL_2»', $token('phone', '+44 20 7946 0958'));
        $this->assertSame('«EMAIL_1»', $token('email', 'Juan@Example.com'));
        $this->assertSame('«EMAIL_1»', $token('email', 'juan@example.com'));
        $this->assertSame('«IBAN_1»', $token('iban', 'ES91 2100 0418 4502 0005 1332'));
        $this->assertSame('«IBAN_1»', $token('iban', 'ES9121000418450200051332'));

        // Each restores to the form seen first.
        $this->assertSame('12345678Z, +34 600 123 123', $map->restore('«DNI_1», «TEL_1»'));
    }

    public function test_own_types_grouped_like_names_regroup_and_survive_to_array(): void
    {
        $strategy = new TokenStrategy('«%s_%d»', ['client' => 'CLIENT']);
        $token = fn (TokenMap $map, string $value) => $map->replacementFor(new Span(0, strlen($value), 'client', $value), $strategy);

        // Literal at first: each written form gets its own token.
        $map = new TokenMap();
        $this->assertSame('«CLIENT_1»', $token($map, 'Acme Health'));
        $this->assertSame('«CLIENT_2»', $token($map, 'ACME HEALTH'));

        // Grouped from now on; the token already issued still restores.
        $map->groupLikeNames(['client']);
        $this->assertSame('«CLIENT_1»', $token($map, 'ACME HEALTH'));
        $this->assertSame('ACME HEALTH', $map->restore('«CLIENT_2»'));

        // A map loaded from the vault keeps grouping the type.
        $this->assertSame('«CLIENT_1»', $token(TokenMap::fromArray($map->toArray()), 'Acme health'));
    }

    public function test_a_literal_entity_survives_to_array(): void
    {
        $strategy = new TokenStrategy('«%s_%d»', ['ref' => 'REF']);
        $token = fn (TokenMap $map, string $value) => $map->replacementFor(new Span(0, strlen($value), 'ref', $value), $strategy);

        $map = new TokenMap();
        $map->groupLikeNames(['ref']);
        $map->keepLiteral([['type' => 'ref', 'value' => 'AB-12']]);

        $this->assertSame('«REF_1»', $token($map, 'AB-12'));
        $this->assertSame('«REF_2»', $token($map, 'ab-12'));
        $this->assertSame('«REF_2»', $token(TokenMap::fromArray($map->toArray()), 'ab-12'));
        $this->assertSame('«REF_3»', $token(TokenMap::fromArray($map->toArray()), 'Ab-12'));
    }

    public function test_a_map_saved_by_1_2_still_groups_its_values(): void
    {
        $map = TokenMap::fromArray([
            'entries' => ['«AP_1»' => 'García'],
            'index' => ['surname|García' => '«AP_1»'],
            'counters' => ['surname' => 1],
        ]);

        $strategy = new TokenStrategy('«%s_%d»', ['surname' => 'AP']);

        $this->assertSame('«AP_1»', $map->replacementFor(new Span(0, 7, 'surname', 'GARCÍA'), $strategy));
        $this->assertSame('García', $map->restore('«AP_1»'));
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
