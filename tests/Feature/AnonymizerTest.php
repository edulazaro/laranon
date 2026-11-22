<?php

namespace EduLazaro\Laranon\Tests\Feature;

use Closure;
use EduLazaro\Laranon\Anonymizer;
use EduLazaro\Laranon\Contracts\VaultStore;
use EduLazaro\Laranon\Strategies\FakerStrategy;
use EduLazaro\Laranon\Support\Checksums;
use EduLazaro\Laranon\Support\Span;
use EduLazaro\Laranon\Support\TokenMap;
use EduLazaro\Laranon\Tests\TestCase;
use EduLazaro\Laranon\Vault\ArrayVaultStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use ReflectionClassConstant;

class AnonymizerTest extends TestCase
{
    protected function anonymizer(): Anonymizer
    {
        return $this->app->make('laranon');
    }

    public function test_round_trip_with_token_strategy(): void
    {
        $text = 'El cliente con DNI 12345678Z (maria@example.com) reclama 4.000 euros.';

        $result = $this->anonymizer()->anonymize($text);

        $this->assertStringNotContainsString('12345678Z', $result->text);
        $this->assertStringNotContainsString('maria@example.com', $result->text);
        $this->assertStringContainsString('«DNI_1»', $result->text);
        $this->assertStringContainsString('«EMAIL_1»', $result->text);

        $response = 'Procede estimar la reclamación de «DNI_1» notificando a «EMAIL_1».';

        $this->assertSame(
            'Procede estimar la reclamación de 12345678Z notificando a maria@example.com.',
            $result->restore($response),
        );
    }

    public function test_scoped_maps_keep_tokens_stable_across_calls(): void
    {
        $first = $this->anonymizer()->scope('chat-1')->anonymize('El DNI del actor es 12345678Z.');
        $second = $this->anonymizer()->scope('chat-1')->anonymize('Consta 12345678Z y también 87654321X.');

        $this->assertStringContainsString('«DNI_1»', $first->text);
        $this->assertStringContainsString('«DNI_1»', $second->text);
        $this->assertStringContainsString('«DNI_2»', $second->text);

        $this->assertSame(
            'Los DNI son 12345678Z y 87654321X.',
            $this->anonymizer()->scope('chat-1')->restore('Los DNI son «DNI_1» y «DNI_2».'),
        );
    }

    public function test_a_closing_quote_is_not_part_of_the_name(): void
    {
        $this->assertSame(
            '‘«PER_M_1» «AP_1»’ declaró. Luego «AP_1» se fue. \'«PER_F_2» «AP_2»\' firma y «AP_2» sale.',
            $this->anonymizer()->anonymize('‘Juan García’ declaró. Luego García se fue. \'Ana López\' firma y López sale.')->text,
        );
    }

    public function test_words_on_the_line_after_a_name_stay_in_cleartext(): void
    {
        $text = "D.ª Raquel Blázquez Martín\nEn Madrid, a 21 de julio.\nProcurador/a: Jorge Rodriguez Simon\nAbogado/a: Ana Ruiz\nEn el recurso.";

        $this->assertSame(
            "D.ª Raquel «AP_1» «AP_2»\nEn Madrid, a 21 de julio.\nProcurador/a: Jorge «AP_3» «AP_4»\nAbogado/a: Ana «AP_5»\nEn el recurso.",
            $this->anonymizer()->except('person')->anonymize($text)->text,
        );
    }

    public function test_only_restricts_detection(): void
    {
        $result = $this->anonymizer()
            ->only('email')
            ->anonymize('DNI 12345678Z, email maria@example.com.');

        $this->assertStringContainsString('12345678Z', $result->text);
        $this->assertStringNotContainsString('maria@example.com', $result->text);
    }

    public function test_only_with_an_unknown_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[dnii]');

        $this->anonymizer()->only(['dnii'])->anonymize('DNI 12345678Z.');
    }

    public function test_given_name_tokens_say_their_gender(): void
    {
        $original = 'María firmó. Comparecen María López, Juan Pérez y Reyes Martín.';

        $result = $this->anonymizer()->anonymize($original);

        // Reyes is ambiguous in the census, so its token stays neutral.
        $this->assertSame('«PER_F_1» firmó. Comparecen «PER_F_1» «AP_1», «PER_M_2» «AP_2» y «PER_3» «AP_3».', $result->text);
        $this->assertSame($original, $result->restore($result->text));
    }

    public function test_gender_can_be_turned_off(): void
    {
        $this->app['config']->set('laranon.gender', false);

        $this->assertSame('Comparece «PER_1» «AP_1».', $this->anonymizer()->anonymize('Comparece María López.')->text);
    }

    public function test_ways_of_writing_a_name_share_one_token(): void
    {
        $result = $this->anonymizer()->anonymize("DEMANDANTE: JUAN GARCÍA\nComparece Juan García. Lo ratifica el SR. GARCIA.");

        $this->assertSame("DEMANDANTE: «PER_M_1» «AP_1»\nComparece «PER_M_1» «AP_1». Lo ratifica el SR. «AP_1».", $result->text);
    }

    public function test_a_name_seen_in_several_forms_restores_to_the_one_that_fits(): void
    {
        $result = $this->anonymizer()->anonymize("DEMANDANTE: JUAN GARCÍA\nComparece Juan García.");

        $this->assertSame(
            "PARTE: JUAN GARCÍA\nEl demandante Juan García reclama.",
            $result->restore("PARTE: «PER_M_1» «AP_1»\nEl demandante «PER_M_1» «AP_1» reclama."),
        );

        // The input itself comes back as it was.
        $this->assertSame("DEMANDANTE: JUAN GARCÍA\nComparece Juan García.", $result->restore($result->text));
    }

    public function test_a_name_seen_in_one_form_always_restores_to_it(): void
    {
        $result = $this->anonymizer()->anonymize('Comparece Juan García.');

        $this->assertSame('PARTE: Juan García', $result->restore('PARTE: «PER_M_1» «AP_1»'));
    }

    public function test_an_identifier_written_in_several_ways_shares_its_token(): void
    {
        $result = $this->anonymizer()->anonymize('DNI 12345678Z, también escrito 12.345.678-Z, y teléfono +34 600 123 123 o 600123123.');

        $this->assertSame('DNI «DNI_1», también escrito «DNI_1», y teléfono «TEL_1» o «TEL_1».', $result->text);
    }

    public function test_own_entity_types_share_their_token_across_written_forms(): void
    {
        $result = $this->anonymizer()
            ->withEntities([['value' => 'Acme Health', 'type' => 'client']])
            ->anonymize("PARTE: ACME HEALTH\nContra Acme Health se dirige la acción.");

        $this->assertSame("PARTE: «CLIENT_1»\nContra «CLIENT_1» se dirige la acción.", $result->text);
        $this->assertSame("PARTE: ACME HEALTH\nContra Acme Health se dirige la acción.", $result->restore($result->text));
    }

    public function test_a_known_entity_of_a_built_in_type_keeps_that_type_grouping(): void
    {
        $result = $this->anonymizer()
            ->withEntities([['value' => '12345678Z', 'type' => 'dni']])
            ->anonymize('DNI 12345678Z y también 12345678-Z.');

        $this->assertSame('DNI «DNI_1» y también «DNI_1».', $result->text);
    }

    public function test_an_entity_with_variants_false_keeps_each_written_form_apart(): void
    {
        $result = $this->anonymizer()
            ->withEntities([
                ['value' => 'Acme Health', 'type' => 'client'],
                ['value' => 'Initech SL', 'type' => 'client', 'variants' => false],
            ])
            ->anonymize("PARTES: ACME HEALTH, INITECH SL\nContra Acme Health e Initech SL.");

        // Same type, decided per entity: Acme shares its token, Initech does not.
        $this->assertSame("PARTES: «CLIENT_1», «CLIENT_2»\nContra «CLIENT_1» e «CLIENT_3».", $result->text);
        $this->assertSame("PARTES: ACME HEALTH, INITECH SL\nContra Acme Health e Initech SL.", $result->restore($result->text));
    }

    public function test_a_known_entity_in_capitals_shares_its_token(): void
    {
        $result = $this->anonymizer()->withEntities(['Acme Health'])->anonymize('ACME HEALTH contra Acme Health.');

        $this->assertSame('«ENT_1» contra «ENT_1».', $result->text);
    }

    public function test_only_accepts_every_type_a_recognizer_emits(): void
    {
        $surnames = $this->anonymizer()->only('surname')->anonymize('Firma María López.');

        $clients = $this->anonymizer()->only('client')
            ->withEntities([['value' => 'Initech SL', 'type' => 'client']])
            ->anonymize('Contra Initech SL.');

        $this->assertSame('Firma María «AP_1».', $surnames->text);
        $this->assertSame('Contra «CLIENT_1».', $clients->text);

        // A typo in except() only anonymizes more, so it does not throw.
        $this->assertSame('Firma «PER_F_1» «AP_1».', $this->anonymizer()->except('persona')->anonymize('Firma María López.')->text);
    }

    public function test_redact_strategy_is_one_way(): void
    {
        $result = $this->anonymizer()->strategy('redact')->anonymize('DNI 12345678Z.');

        $this->assertSame('DNI [DNI].', $result->text);
        $this->assertSame([], $result->map->entries());
    }

    public function test_faker_strategy_produces_deterministic_valid_surrogates(): void
    {
        $a = $this->anonymizer()->strategy('faker')->anonymize('DNI 12345678Z.');
        $b = $this->anonymizer()->strategy('faker')->anonymize('DNI 12345678Z.');

        $this->assertSame($a->text, $b->text);
        $this->assertStringNotContainsString('12345678Z', $a->text);

        preg_match('/\d{8}[A-Z]/', $a->text, $m);
        $this->assertTrue(Checksums::dni($m[0]));

        $this->assertSame('DNI 12345678Z.', $a->restore($a->text));
    }

    public function test_known_entities_from_values(): void
    {
        $result = $this->anonymizer()
            ->withEntities([['value' => 'Bufete Andrade y Costa', 'type' => 'entity']])
            ->anonymize('Contra Bufete Andrade y Costa se dirige la acción.');

        $this->assertStringNotContainsString('Bufete Andrade y Costa', $result->text);
        $this->assertStringContainsString('«ENT_1»', $result->text);
    }

    public function test_helpers_round_trip(): void
    {
        $result = anon('El teléfono +34 612 345 678 es del testigo.');

        $this->assertStringContainsString('«TEL_1»', $result->text);
        $this->assertSame(
            'El teléfono +34 612 345 678 es del testigo.',
            deanon($result->text, $result),
        );
    }

    public function test_phone_formats_bare_spaced_and_international(): void
    {
        $phones = [
            '600123123',          // bare mobile, no separators
            '600 123 123',        // bare mobile, 3-3-3
            '600 12 31 23',       // bare mobile, 3-2-2-2
            '600-12-31-23',       // bare mobile, hyphens
            '915 123 456',        // bare landline
            '+34 612 345 678',    // Spain, prefixed
            '+34612345678',       // Spain, prefixed no separators
            '0034 600 123 123',   // Spain, 00 prefix
            '+376 812 345',       // Andorra, prefixed
            '+44 20 7946 0958',   // UK, prefixed
            '+33 6 12 34 56 78',  // France, prefixed
        ];

        foreach ($phones as $phone) {
            $text = "Contacto: {$phone}.";
            $result = $this->anonymizer()->anonymize($text);

            // The WHOLE number (prefix included) collapses into a single token.
            $this->assertSame("Contacto: «TEL_1».", $result->text, "no tokenizó completo: {$phone}");
            $this->assertSame($text, $result->restore($result->text), "no restaura: {$phone}");
        }
    }

    public function test_plain_numbers_are_not_mistaken_for_phones(): void
    {
        // A short quantity and a decimal must not be swallowed as phones.
        $result = $this->anonymizer()->anonymize('Compró 600 unidades por 1.234,56 euros.');

        $this->assertStringNotContainsString('«TEL', $result->text);
        $this->assertSame('Compró 600 unidades por 1.234,56 euros.', $result->restore($result->text));
    }

    public function test_surname_honorific_types_all_words_as_surname(): void
    {
        // "Sr./Sra." introduce surnames: with several words all are surnames
        // ("Sr. Pérez López"), so none is mistaken for a given name (which
        // except('person') would otherwise leave in cleartext).
        $result = $this->anonymizer()->except('person')->anonymize('Comparece el Sr. Pérez López.');

        $this->assertStringNotContainsString('Pérez', $result->text);
        $this->assertStringNotContainsString('López', $result->text);
        $this->assertSame(2, substr_count($result->text, '«AP_'));
        $this->assertSame('Comparece el Sr. Pérez López.', $result->restore($result->text));
    }

    public function test_dni_with_thousand_dots(): void
    {
        $result = $this->anonymizer()->anonymize('aporta el DNI 12.345.678-Z en autos');

        $this->assertStringContainsString('«DNI_1»', $result->text);
        $this->assertStringNotContainsString('12.345.678', $result->text);
        $this->assertSame('aporta el DNI 12.345.678-Z en autos', $result->restore($result->text));
    }

    public function test_name_words_get_independent_tokens_and_restore_exactly(): void
    {
        $original = 'La reclamante María López presentó la demanda. María adjuntó el contrato firmado.';

        $result = $this->anonymizer()->anonymize($original);

        $this->assertStringNotContainsString('María', $result->text);
        $this->assertStringNotContainsString('López', $result->text);
        $this->assertSame(2, substr_count($result->text, '«PER_F_1»'));
        $this->assertSame(1, substr_count($result->text, '«AP_1»'));

        // Restoration is byte-exact: each token maps back to the literal word.
        $this->assertSame($original, $result->restore($result->text));
    }

    public function test_two_people_sharing_a_first_name_share_the_given_name_token(): void
    {
        $original = 'Comparecen María López y María Fernández. María firmó primero.';

        $result = $this->anonymizer()->anonymize($original);

        $this->assertStringNotContainsString('María', $result->text);
        $this->assertStringNotContainsString('López', $result->text);
        $this->assertStringNotContainsString('Fernández', $result->text);

        // Same given-name token everywhere, different surname tokens: the
        // LLM sees exactly the structure a human reader sees.
        $this->assertSame(3, substr_count($result->text, '«PER_F_1»'));
        $this->assertSame(1, substr_count($result->text, '«AP_1»'));
        $this->assertSame(1, substr_count($result->text, '«AP_2»'));

        $this->assertSame($original, $result->restore($result->text));
    }

    public function test_stable_entities_pin_the_same_token_across_scopes(): void
    {
        $entities = [['value' => 'Clínicas Estetik SL', 'type' => 'entity', 'id' => 47]];

        $first = $this->anonymizer()->scope('chat-1')->withStableEntities($entities)
            ->anonymize('Contra Clínicas Estetik SL se dirige la acción.');

        $second = $this->anonymizer()->scope('chat-2')->withStableEntities($entities)
            ->anonymize('Se notifica a Clínicas Estetik SL.');

        $this->assertStringContainsString('«ENT_47»', $first->text);
        $this->assertStringContainsString('«ENT_47»', $second->text);
    }

    public function test_a_pinned_id_never_takes_a_counter_token_of_another_value(): void
    {
        $scoped = $this->anonymizer()->scope('exp-pinned');

        // The counter hands «ENT_1» to Globex first...
        $scoped->withEntities([['value' => 'Globex Corp', 'type' => 'entity']])
            ->anonymize('Demanda contra Globex Corp.');

        // ...so Initech, pinned to id 1, cannot take it and gets the next one.
        $result = $scoped->withStableEntities([['value' => 'Initech SL', 'type' => 'entity', 'id' => 1]])
            ->anonymize('Contesta Initech SL.');

        $this->assertSame('Contesta «ENT_2».', $result->text);
        $this->assertSame('Contesta Initech SL.', $result->restore($result->text));
        $this->assertSame('Globex Corp e Initech SL', $scoped->restore('«ENT_1» e «ENT_2»'));
    }

    public function test_a_pinned_id_reused_for_another_value_falls_back_to_a_regular_token(): void
    {
        $scoped = $this->anonymizer()->scope('exp-renamed');

        $scoped->withStableEntities([['value' => 'Initech SL', 'type' => 'entity', 'id' => 7]])
            ->anonymize('Contesta Initech SL.');

        $renamed = $scoped->withStableEntities([['value' => 'Initech Group SA', 'type' => 'entity', 'id' => 7]])
            ->anonymize('Contesta Initech Group SA.');

        $this->assertStringNotContainsString('«ENT_7»', $renamed->text);
        $this->assertSame('Contesta Initech Group SA.', $renamed->restore($renamed->text));
        $this->assertSame('Initech SL', $scoped->restore('«ENT_7»'));
    }

    public function test_stable_entities_apply_in_a_session(): void
    {
        $session = $this->anonymizer()
            ->withStableEntities([['value' => 'Initech SL', 'type' => 'entity', 'id' => 47]])
            ->newSession();

        $this->assertSame('Contesta «ENT_47».', $session->anonymize('Contesta Initech SL.'));
    }

    public function test_1x_constructor_calls_still_work(): void
    {
        $engine = new Anonymizer(
            [new \EduLazaro\Laranon\Recognizers\Es\DniRecognizer()],
            ['token' => new \EduLazaro\Laranon\Strategies\TokenStrategy('«%s_%d»', ['dni' => 'DNI'])],
            'token',
            new ArrayVaultStore(),
            null,
            '«',
            '»',
        );

        $result = $engine->anonymize('DNI 12345678Z.');
        $stream = new \EduLazaro\Laranon\Streaming\StreamDeanonymizer($result->map, '«', '»');
        $session = new \EduLazaro\Laranon\AnonymizerSession($engine, new TokenMap(), '«', '»');

        $this->assertSame('DNI «DNI_1».', $result->text);
        $this->assertSame('DNI 12345678Z.', $stream->push($result->text) . $stream->flush());
        $this->assertSame('DNI «DNI_1».', $session->anonymize('DNI 12345678Z.'));
    }

    public function test_concurrent_calls_on_a_scope_are_serialized(): void
    {
        $this->app['config']->set('laranon.vault.lock_wait', 0);

        // Request B arrives between A's read of the vault and A's write.
        $vault = new class(new ArrayVaultStore()) implements VaultStore
        {
            public ?Closure $between = null;

            public function __construct(protected VaultStore $inner)
            {
            }

            public function get(string $key): ?array
            {
                $payload = $this->inner->get($key);

                if ($between = $this->between) {
                    $this->between = null;
                    $between();
                }

                return $payload;
            }

            public function put(string $key, array $payload, ?int $ttl = null): void
            {
                $this->inner->put($key, $payload, $ttl);
            }

            public function forget(string $key): void
            {
                $this->inner->forget($key);
            }
        };

        $this->app->instance(VaultStore::class, $vault);

        $blocked = null;

        $vault->between = function () use (&$blocked) {
            try {
                $this->anonymizer()->scope('exp-race')->anonymize('B: DNI 87654321X.');
            } catch (LockTimeoutException $e) {
                $blocked = $e;
            }
        };

        $a = $this->anonymizer()->scope('exp-race')->anonymize('A: DNI 12345678Z.');

        $this->assertInstanceOf(LockTimeoutException::class, $blocked);
        $this->assertSame('A: DNI 12345678Z.', $this->anonymizer()->scope('exp-race')->restore($a->text));

        // Once A is done, B goes through on top of A's map.
        $b = $this->anonymizer()->scope('exp-race')->anonymize('B: DNI 87654321X.');

        $this->assertSame('B: DNI «DNI_2».', $b->text);
        $this->assertSame('B: DNI 87654321X.', $this->anonymizer()->scope('exp-race')->restore($b->text));
    }

    public function test_faker_never_picks_a_surrogate_already_in_the_text(): void
    {
        // Whatever surname López gets, one of these texts already contains
        // it as a plain word, and that word must restore to itself.
        $pool = (new ReflectionClassConstant(FakerStrategy::class, 'LAST_NAMES'))->getValue();

        foreach ($pool as $surname) {
            $text = "La actora, María López, recurre. Lo firma {$surname}.";
            $result = $this->anonymizer()->strategy('faker')->anonymize($text);

            $this->assertStringNotContainsString('López', $result->text);
            $this->assertSame($text, $result->restore($result->text), "Failed with {$surname}");
        }
    }

    public function test_faker_keeps_the_gender_of_a_given_name(): void
    {
        $female = (new ReflectionClassConstant(FakerStrategy::class, 'FEMALE_NAMES'))->getValue();
        $male = (new ReflectionClassConstant(FakerStrategy::class, 'MALE_NAMES'))->getValue();

        $entries = $this->anonymizer()->strategy('faker')->anonymize('Comparecen María López y Juan Pérez.')->map->entries();

        $this->assertContains(array_search('María', $entries, true), $female);
        $this->assertContains(array_search('Juan', $entries, true), $male);
    }

    public function test_faker_never_keeps_the_original_as_its_surrogate(): void
    {
        $faker = new FakerStrategy('«%s_%d»', ['person' => 'PER']);
        $pool = (new ReflectionClassConstant(FakerStrategy::class, 'FIRST_NAMES'))->getValue();

        foreach ($pool as $name) {
            $replacement = (new TokenMap())->replacementFor(new Span(0, strlen($name), 'person', $name), $faker);

            $this->assertNotSame($name, $replacement, "{$name} would go out in clear");
        }
    }

    public function test_an_earlier_surrogate_reappearing_as_a_real_word_restores_to_itself(): void
    {
        $session = $this->anonymizer()->strategy('faker')->newSession();

        $first = $session->anonymize('La actora, María López, recurre.');
        $surrogate = array_search('López', $session->map()->entries(), true);

        $text = "En la sala estaba {$surrogate} como testigo.";
        $second = $session->anonymize($text);

        $this->assertSame('La actora, María López, recurre.', $session->restore($first));
        $this->assertSame($text, $session->restore($second));
    }

    public function test_faker_serves_every_name_of_a_gender_before_falling_back(): void
    {
        $map = new TokenMap();
        $faker = new FakerStrategy('«%s_%d»', ['person' => 'PER']);
        $female = (new ReflectionClassConstant(FakerStrategy::class, 'FEMALE_NAMES'))->getValue();

        // Men and women share the given-name counter: 10 men first, then as
        // many women as the pool can still serve before the 50-name limit.
        for ($i = 0; $i < 10; $i++) {
            $map->replacementFor(new Span(0, 1, 'person', "Man{$i}", 0.9, 'm'), $faker);
        }

        for ($i = 0; $i < 40; $i++) {
            $this->assertContains($map->replacementFor(new Span(0, 1, 'person', "Woman{$i}", 0.9, 'f'), $faker), $female);
        }
    }

    public function test_faker_falls_back_to_tokens_when_its_name_pool_runs_out(): void
    {
        $map = new TokenMap();
        $faker = new FakerStrategy('«%s_%d»', ['person' => 'PER']);

        $replacements = [];

        for ($i = 0; $i < 250; $i++) {
            $replacements[] = $map->replacementFor(new Span(0, 1, 'person', "Name{$i}"), $faker);
        }

        $this->assertCount(250, array_unique($replacements));

        foreach ($replacements as $replacement) {
            $this->assertMatchesRegularExpression('/^(\p{Lu}\p{Ll}+|«PER_\d+»)$/u', $replacement);
        }

        $this->assertCount(100, preg_grep('/^\p{Lu}/u', $replacements));
    }

    public function test_bare_name_word_reuses_the_token_from_an_earlier_turn(): void
    {
        $scoped = $this->anonymizer()->scope('chat-continuity');

        $turn1 = $scoped->anonymize('La demandante María López presentó el escrito.');
        $this->assertStringContainsString('«PER_F_1»', $turn1->text);
        $this->assertStringContainsString('«AP_1»', $turn1->text);

        // Turn 2 never repeats the surname; the word "María" still resolves
        // to its own token, and restores to exactly the word "María".
        $turn2 = $scoped->anonymize('María confirmó los datos aportados.');

        $this->assertStringNotContainsString('María', $turn2->text);
        $this->assertStringContainsString('«PER_F_1»', $turn2->text);
        $this->assertSame('María confirmó los datos aportados.', $turn2->restore($turn2->text));
    }

    public function test_case_reference_is_detected_with_the_spelled_out_numero(): void
    {
        $result = $this->anonymizer()->anonymize(
            'El expediente numero 456/2023 fue admitido. El procedimiento 789/2022 sigue en curso.',
        );

        $this->assertStringNotContainsString('456/2023', $result->text);
        $this->assertStringNotContainsString('789/2022', $result->text);
    }
}
