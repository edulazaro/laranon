<?php

namespace EduLazaro\Laranon\Tests\Feature;

use EduLazaro\Laranon\Anonymizer;
use EduLazaro\Laranon\Support\Checksums;
use EduLazaro\Laranon\Tests\TestCase;

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

    public function test_only_restricts_detection(): void
    {
        $result = $this->anonymizer()
            ->only('email')
            ->anonymize('DNI 12345678Z, email maria@example.com.');

        $this->assertStringContainsString('12345678Z', $result->text);
        $this->assertStringNotContainsString('maria@example.com', $result->text);
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
        $this->assertSame(2, substr_count($result->text, '«PER_1»'));
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
        $this->assertSame(3, substr_count($result->text, '«PER_1»'));
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

    public function test_bare_name_word_reuses_the_token_from_an_earlier_turn(): void
    {
        $scoped = $this->anonymizer()->scope('chat-continuity');

        $turn1 = $scoped->anonymize('La demandante María López presentó el escrito.');
        $this->assertStringContainsString('«PER_1»', $turn1->text);
        $this->assertStringContainsString('«AP_1»', $turn1->text);

        // Turn 2 never repeats the surname; the word "María" still resolves
        // to its own token, and restores to exactly the word "María".
        $turn2 = $scoped->anonymize('María confirmó los datos aportados.');

        $this->assertStringNotContainsString('María', $turn2->text);
        $this->assertStringContainsString('«PER_1»', $turn2->text);
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
