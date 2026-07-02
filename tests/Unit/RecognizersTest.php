<?php

namespace EduLazaro\Laranon\Tests\Unit;

use EduLazaro\Laranon\Recognizers\Es\DniRecognizer;
use EduLazaro\Laranon\Recognizers\Es\PersonNameRecognizer;
use EduLazaro\Laranon\Recognizers\Universal\EmailRecognizer;
use EduLazaro\Laranon\Recognizers\Universal\IbanRecognizer;
use EduLazaro\Laranon\Recognizers\Universal\KnownEntitiesRecognizer;
use EduLazaro\Laranon\Support\SpanResolver;
use PHPUnit\Framework\TestCase;

class RecognizersTest extends TestCase
{
    public function test_dni_recognizer_requires_a_valid_control_letter(): void
    {
        $spans = (new DniRecognizer())->detect('Mi DNI es 12345678Z y el otro es 12345678A.');

        $this->assertCount(1, $spans);
        $this->assertSame('12345678Z', $spans[0]->value);
        $this->assertSame('dni', $spans[0]->type);
    }

    public function test_email_recognizer(): void
    {
        $spans = (new EmailRecognizer())->detect('Escribe a maria.garcia@despacho.legal cuando puedas.');

        $this->assertCount(1, $spans);
        $this->assertSame('maria.garcia@despacho.legal', $spans[0]->value);
    }

    public function test_iban_recognizer_accepts_spaced_ibans_and_validates_mod97(): void
    {
        $spans = (new IbanRecognizer())->detect('Cuenta: ES91 2100 0418 4502 0005 1332. Falsa: ES91 2100 0418 4502 0005 1333.');

        $this->assertCount(1, $spans);
        $this->assertSame('ES91 2100 0418 4502 0005 1332', $spans[0]->value);
    }

    public function test_person_name_via_honorific_splits_into_word_spans(): void
    {
        // The honorific and the plain pattern both hit here; SpanResolver
        // dedupes the overlaps exactly as the anonymizer pipeline does.
        $spans = SpanResolver::resolve(
            (new PersonNameRecognizer())->detect('Comparece D. Juan Pérez García en calidad de demandante.'),
        );

        $this->assertCount(3, $spans);
        $this->assertSame(['Juan', 'Pérez', 'García'], array_map(fn ($s) => $s->value, $spans));
        $this->assertSame(['person', 'surname', 'surname'], array_map(fn ($s) => $s->type, $spans));
    }

    public function test_person_name_via_gazetteer_gate(): void
    {
        $recognizer = new PersonNameRecognizer();

        $hit = $recognizer->detect('La reclamante María García presentó la solicitud.');
        $this->assertCount(2, $hit);
        $this->assertSame('María', $hit[0]->value);
        $this->assertSame('person', $hit[0]->type);
        $this->assertSame('García', $hit[1]->value);
        $this->assertSame('surname', $hit[1]->type);

        // A capitalized sequence whose first token is not a given name is out.
        $miss = $recognizer->detect('El Tribunal Supremo resolvió el recurso.');
        $this->assertEmpty($miss);
    }

    public function test_lone_surname_after_honorific_is_typed_as_surname(): void
    {
        $spans = (new PersonNameRecognizer())->detect('Se notifica al Sr. García el resultado.');

        $this->assertCount(1, $spans);
        $this->assertSame('García', $spans[0]->value);
        $this->assertSame('surname', $spans[0]->type);
    }

    public function test_ambiguous_name_requires_a_confirmed_surname(): void
    {
        $recognizer = new PersonNameRecognizer();

        // "Luz" alone (no confirmed surname following it) is left alone.
        $bare = $recognizer->detect('Se presentó Luz Xyzabc en la reunión.');
        $this->assertEmpty($bare);

        // Followed by a confirmed surname, both words are detected.
        $withSurname = $recognizer->detect('La testigo Luz Martínez firmó la declaración.');
        $this->assertCount(2, $withSurname);
        $this->assertSame('Luz', $withSurname[0]->value);
        $this->assertSame('Martínez', $withSurname[1]->value);

        // The honorific pattern does not consult the gazetteer, so a bare
        // ambiguous name still matches when explicitly introduced as a person.
        $honorific = $recognizer->detect('Comparece Dña. Luz, mayor de edad, con DNI válido.');
        $this->assertNotEmpty($honorific);
        $this->assertSame('Luz', $honorific[0]->value);
        $this->assertSame('person', $honorific[0]->type);
    }

    public function test_honorific_titles_are_never_tokenized_as_name_words(): void
    {
        // "dona"/"don" exist in the census gazetteer as compound-name
        // artifacts; the title in "Doña Luz" must not become a name span.
        $spans = SpanResolver::resolve(
            (new PersonNameRecognizer())->detect('Doña Luz declaró después.'),
        );

        $this->assertCount(1, $spans);
        $this->assertSame('Luz', $spans[0]->value);
        $this->assertSame('person', $spans[0]->type);
    }

    public function test_known_entities_match_case_insensitively_with_word_bounds(): void
    {
        $recognizer = new KnownEntitiesRecognizer([
            ['value' => 'Clínicas Estetik SL', 'type' => 'entity'],
        ]);

        $spans = $recognizer->detect('La demandada CLÍNICAS ESTETIK SL no compareció.');

        $this->assertCount(1, $spans);
        $this->assertSame('CLÍNICAS ESTETIK SL', $spans[0]->value);
    }
}
