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

    public function test_nif_k_l_m_carries_the_dni_control_letter_of_its_digits(): void
    {
        $values = fn (string $text) => array_map(fn ($span) => $span->value, (new DniRecognizer())->detect($text));

        $this->assertSame(['K1234567L', 'L-1234567-L', 'M7654321J'], $values('NIF K1234567L, L-1234567-L y M7654321J.'));
        $this->assertSame([], $values('NIF K1234567A, L7654321Z y M1234567J.'));
    }

    public function test_dni_with_digits_grouped_by_blanks(): void
    {
        $spans = (new DniRecognizer())->detect("NIF 12 345 678 Z y NIF 12\u{00A0}345\u{00A0}678-Z.");

        $this->assertSame(['12 345 678 Z', "12\u{00A0}345\u{00A0}678-Z"], array_map(fn ($span) => $span->value, $spans));

        // Lowercase after grouped digits reads as a word, not a control letter.
        $this->assertSame([], (new DniRecognizer())->detect('Recibió 12 345 678 z.'));
    }

    public function test_cif_with_digits_grouped_by_dots(): void
    {
        $spans = (new \EduLazaro\Laranon\Recognizers\Es\CifRecognizer())->detect('CIF B-12.345.674 y CIF B12.345.674.');

        $this->assertSame(['B-12.345.674', 'B12.345.674'], array_map(fn ($span) => $span->value, $spans));
    }

    public function test_spanish_vat_number_wraps_a_dni_nie_or_cif(): void
    {
        $recognizer = new \EduLazaro\Laranon\Recognizers\Es\VatRecognizer();

        foreach (['ESA58818501', 'ES A58818501', 'ES-A58818501', 'ES 12345678Z', 'ESX1234567L'] as $vat) {
            $spans = $recognizer->detect("NIF-IVA {$vat}.");

            $this->assertCount(1, $spans, $vat);
            $this->assertSame($vat, $spans[0]->value);
            $this->assertSame('vat', $spans[0]->type);
        }

        // A wrong control fails, and the lowercase verb "es" is not a prefix.
        $this->assertSame([], $recognizer->detect('NIF-IVA ESA58818502.'));
        $this->assertSame([], $recognizer->detect('El NIF es 12345678Z.'));
    }

    public function test_provincial_plates_need_a_word_that_introduces_them(): void
    {
        $recognizer = new \EduLazaro\Laranon\Recognizers\Es\PlateRecognizer();

        $values = fn (string $text) => array_map(fn ($span) => $span->value, $recognizer->detect($text));

        $this->assertSame(['M-1234-AB'], $values('El coche con matrícula M-1234-AB.'));
        $this->assertSame(['B 1234 CD'], $values('Vehículo: B 1234 CD, retirado.'));
        $this->assertSame([], $values('Referencia interna M-1234-AB.'));
        $this->assertSame([], $values('Con matrícula M-1234 AB.'));
    }

    public function test_a_bic_needs_a_word_that_introduces_it(): void
    {
        $values = fn (string $text) => array_map(fn ($span) => $span->value, (new \EduLazaro\Laranon\Recognizers\Universal\BicSwiftRecognizer())->detect($text));

        $this->assertSame([], $values('SE REQUIERE a la parte. EXPEDIENTE ATRASADO.'));
        $this->assertSame(['CAIXESBBXXX'], $values('IBAN ES91 2100 0418 4502 0005 1332, BIC: CAIXESBBXXX.'));
        $this->assertSame(['BSCHESMM'], $values('Código SWIFT/BIC de la entidad: BSCHESMM.'));
    }

    public function test_the_year_of_a_reference_is_not_a_plate(): void
    {
        $values = fn (string $text) => array_map(fn ($span) => $span->value, (new \EduLazaro\Laranon\Recognizers\Es\PlateRecognizer())->detect($text));

        $this->assertSame([], $values('Según la Ley 50/1980 LCS y la STS 123/2019 BCN.'));
        $this->assertSame(['1234 BCD', '1234-BCD'], $values('Matrícula 1234 BCD, antes 1234-BCD.'));
    }

    public function test_a_column_of_amounts_is_not_a_card(): void
    {
        $recognizer = new \EduLazaro\Laranon\Recognizers\Universal\CreditCardRecognizer();

        $this->assertSame([], $recognizer->detect("Importes:\n4500\n1000\n1800\n1300\nTotal."));
        $this->assertCount(1, $recognizer->detect('Tarjeta 4500 1000 1800 1300 caducada.'));
        $this->assertCount(1, $recognizer->detect("Tarjeta 4500\u{00A0}1000-1800-1300 caducada."));
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

    public function test_iban_followed_by_an_uppercase_word_is_still_detected(): void
    {
        foreach ([
            'CUENTA ES91 2100 0418 4502 0005 1332 DEL TITULAR',
            'IBAN ES91 2100 0418 4502 0005 1332 EN CAIXABANK',
            'IBAN ES91 2100 0418 4502 0005 1332 1234 EUR',
        ] as $text) {
            $spans = (new IbanRecognizer())->detect($text);

            $this->assertCount(1, $spans, $text);
            $this->assertSame('ES91 2100 0418 4502 0005 1332', $spans[0]->value, $text);
        }
    }

    public function test_iban_groups_split_by_dashes_and_ibans_in_a_row(): void
    {
        $values = fn (string $text) => array_map(fn ($span) => $span->value, (new IbanRecognizer())->detect($text));

        $this->assertSame(['ES91-2100-0418-4502-0005-1332'], $values('IBAN ES91-2100-0418-4502-0005-1332.'));
        $this->assertSame(
            ['ES91 2100 0418 4502 0005 1332', 'ES76 2077 0024 0031 0257 5766'],
            $values('Cuentas ES91 2100 0418 4502 0005 1332 ES76 2077 0024 0031 0257 5766 fin.'),
        );
    }

    public function test_lowercase_iban_is_detected(): void
    {
        $spans = (new IbanRecognizer())->detect('Cuenta es91 2100 0418 4502 0005 1332 de la titular.');

        $this->assertCount(1, $spans);
        $this->assertSame('es91 2100 0418 4502 0005 1332', $spans[0]->value);
    }

    public function test_no_break_spaces_and_typographic_dashes_separate_groups(): void
    {
        $nbsp = "\u{00A0}";

        $cases = [
            [new DniRecognizer(), "DNI 12345678\u{2013}Z", "12345678\u{2013}Z"],
            [new DniRecognizer(), "DNI 12345678{$nbsp}Z", "12345678{$nbsp}Z"],
            [new \EduLazaro\Laranon\Recognizers\Es\NieRecognizer(), "NIE X\u{2011}1234567\u{2011}L", "X\u{2011}1234567\u{2011}L"],
            [new \EduLazaro\Laranon\Recognizers\Es\CccRecognizer(), "CCC 2100{$nbsp}0418{$nbsp}45{$nbsp}0200051332", "2100{$nbsp}0418{$nbsp}45{$nbsp}0200051332"],
            [new IbanRecognizer(), "IBAN ES91{$nbsp}2100{$nbsp}0418{$nbsp}4502{$nbsp}0005{$nbsp}1332.", "ES91{$nbsp}2100{$nbsp}0418{$nbsp}4502{$nbsp}0005{$nbsp}1332"],
            [new \EduLazaro\Laranon\Recognizers\Es\PlateRecognizer(), "matrícula 1234\u{2013}BCD", "1234\u{2013}BCD"],
            [new \EduLazaro\Laranon\Recognizers\Es\PhoneRecognizer(), "tel. 600{$nbsp}123{$nbsp}123", "600{$nbsp}123{$nbsp}123"],
            // Thin space (U+2009), as typesetting puts between digit groups.
            [new DniRecognizer(), "DNI 12\u{2009}345\u{2009}678\u{2009}Z", "12\u{2009}345\u{2009}678\u{2009}Z"],
            [new IbanRecognizer(), "IBAN ES91\u{2009}2100\u{2009}0418\u{2009}4502\u{2009}0005\u{2009}1332.", "ES91\u{2009}2100\u{2009}0418\u{2009}4502\u{2009}0005\u{2009}1332"],
        ];

        foreach ($cases as [$recognizer, $text, $expected]) {
            $values = array_map(fn ($span) => $span->value, $recognizer->detect($text));

            $this->assertContains($expected, $values, $text);
        }
    }

    public function test_name_words_with_inner_capitals_and_apostrophes(): void
    {
        $es = new PersonNameRecognizer();
        $en = new \EduLazaro\Laranon\Recognizers\En\PersonNameRecognizer();
        $values = fn ($recognizer, string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        $this->assertSame(['Juan', 'McDonald'], $values($es, 'Firma Juan McDonald.'));
        $this->assertSame(['Eugenio', "D'Angelo"], $values($es, "Firma Eugenio D'Angelo."));
        $this->assertSame(['Laura', "Dell'Orto"], $values($es, "Firma Laura Dell'Orto."));
        $this->assertSame(['Leonardo', 'DiCaprio'], $values($en, 'Leonardo DiCaprio won.'));
        $this->assertSame(['Douglas', 'MacArthur'], $values($en, 'Signed by Douglas MacArthur.'));
        $this->assertSame(['Shaquille', 'O’Neal'], $values($en, 'Signed by Shaquille O’Neal.'));
        $this->assertSame(['McKellen'], $values($en, 'Mr. McKellen agreed.'));

        // Brands in camel case are not names: no given name opens them.
        $this->assertSame([], $values($es, 'Pagó con PayPal y escribió por WhatsApp a YouTube.'));
    }

    public function test_particles_inside_a_name_stay_in_cleartext(): void
    {
        $es = new PersonNameRecognizer();
        $en = new \EduLazaro\Laranon\Recognizers\En\PersonNameRecognizer();
        $values = fn ($recognizer, string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        $this->assertSame(['Vincent', 'Gogh'], $values($en, 'A painting by Vincent van Gogh.'));
        $this->assertSame(['Johannes', 'Waals'], $values($es, 'Physicist Johannes van der Waals.'));
        $this->assertSame(['Gogh'], $values($en, 'Mr. van Gogh replied.'));
        $this->assertSame(['Alessandro', 'Stefano'], $values($es, 'Firma Alessandro di Stefano.'));
        $this->assertSame(['Maria', 'Silva'], $values($es, 'Comparece Maria da Silva.'));
        $this->assertSame(['Eugeni', "d'Ors"], $values($es, "Escribió Eugeni d'Ors."));
        $this->assertSame(['Mohamed', 'el-Amrani'], $values($es, 'Comparece Mohamed el-Amrani.'));
        $this->assertSame(['Fuente'], $values($es, 'Lo atiende el Sr. de la Fuente.'));

        // "al" alone belongs to the sentence, and a particle never opens a name.
        $this->assertSame(['Juan', 'García'], $values($es, 'Remitido por Juan García al Juzgado.'));
        $this->assertSame([], $values($en, 'Rocket by Wernher von Braun.'));
    }

    public function test_initials_inside_or_before_a_name_stay_in_cleartext(): void
    {
        $es = new PersonNameRecognizer();
        $en = new \EduLazaro\Laranon\Recognizers\En\PersonNameRecognizer();
        $values = fn ($recognizer, string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        $this->assertSame(['Juan', 'Pérez'], $values($es, 'Firma Juan J. Pérez.'));
        $this->assertSame(['María', 'López'], $values($es, 'Comparece María P. López.'));
        $this->assertSame(['José', 'García'], $values($es, 'Comparece M.ª José García.'));
        $this->assertSame(['JUAN', 'PÉREZ', 'GARCÍA'], $values($es, 'Comparece JUAN J. PÉREZ GARCÍA.'));
        $this->assertSame(['John', 'Kennedy'], $values($en, 'President John F. Kennedy.'));

        // An initial and a word are not a name on their own: the surname is
        // only covered once the text names the person in full (see the sweep).
        $this->assertSame([], $values($es, 'Fdo.: E. Lázaro.'));
        $this->assertSame([], $values($en, 'Signed by J. Smith.'));
        $this->assertSame([], $values($es, 'Telefónica S.A. Madrid presentó recurso.'));
        $this->assertSame([], $values($es, 'Según el artículo 1902 C. Civil, procede.'));
        $this->assertSame([], $values($es, 'Hepatitis C. Durante el proceso. Capítulo I. Mayor cuantía.'));
    }

    public function test_a_hyphenated_surname_is_one_surname_word(): void
    {
        $cases = [
            [new PersonNameRecognizer(), 'Lo firma Arturo Pérez-Reverte.', ['Arturo', 'Pérez-Reverte']],
            [new PersonNameRecognizer(), 'Comparece D. Juan Gómez-Acebo Martín.', ['Juan', 'Gómez-Acebo', 'Martín']],
            [new \EduLazaro\Laranon\Recognizers\En\PersonNameRecognizer(), 'Signed by Catherine Zeta-Jones.', ['Catherine', 'Zeta-Jones']],
        ];

        foreach ($cases as [$recognizer, $text, $expected]) {
            $spans = SpanResolver::resolve($recognizer->detect($text));

            $this->assertSame($expected, array_map(fn ($span) => $span->value, $spans), $text);
            $this->assertSame('person', $spans[0]->type, $text);
            $this->assertSame('surname', $spans[1]->type, $text);
        }
    }

    public function test_uppercase_names_with_and_without_honorific(): void
    {
        $recognizer = new PersonNameRecognizer();

        $cases = [
            'DON JUAN GARCÍA LÓPEZ comparece.' => [['JUAN', 'person'], ['GARCÍA', 'surname'], ['LÓPEZ', 'surname']],
            'Comparece JOSÉ LUIS RODRÍGUEZ ZAPATERO, mayor de edad.' => [['JOSÉ', 'person'], ['LUIS', 'surname'], ['RODRÍGUEZ', 'surname'], ['ZAPATERO', 'surname']],
            'VISTOS por DOÑA MARÍA DEL CARMEN RUIZ GÓMEZ, Magistrada.' => [['MARÍA', 'person'], ['CARMEN', 'surname'], ['RUIZ', 'surname'], ['GÓMEZ', 'surname']],
            'Lo atiende el SR. GARCÍA.' => [['GARCÍA', 'surname']],
            'Comparece don Juan Pérez.' => [['Juan', 'person'], ['Pérez', 'surname']],
        ];

        foreach ($cases as $text => $expected) {
            $spans = SpanResolver::resolve($recognizer->detect($text));

            $this->assertSame($expected, array_map(fn ($span) => [$span->value, $span->type], $spans), $text);
        }
    }

    public function test_uppercase_headings_and_places_are_not_names(): void
    {
        $recognizer = new PersonNameRecognizer();

        foreach ([
            'TRIBUNAL SUPREMO. SALA DE LO CIVIL',
            'SEGUNDO MOTIVO DE OPOSICIÓN',
            'contra BANCO SANTANDER, S.A.',
            'Vive en SAN SEBASTIÁN, o en San Sebastián.',
            'SANTA CRUZ DE TENERIFE',
            "FELIPE VI\n\nREY DE ESPAÑA",
            'Contra MERCEDES-BENZ ESPAÑA.',
            'CRUZ ROJA ESPAÑOLA',
            'RECURSO DE AMPARO AVOCADO AL PLENO',
        ] as $text) {
            $this->assertSame([], $recognizer->detect($text), $text);
        }
    }

    public function test_a_brand_or_an_ambiguous_name_needs_more_to_be_a_person(): void
    {
        $recognizer = new PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        $this->assertSame([], $values('Contra Mercedes-Benz España.'));
        $this->assertSame(['María-José', 'Pérez'], $values('Comparece María-José Pérez.'));
        $this->assertSame(['MERCEDES', 'RUIZ', 'GÓMEZ'], $values('Comparece MERCEDES RUIZ GÓMEZ.'));
        $this->assertSame(['CRUZ', 'MARTÍN', 'LÓPEZ'], $values('Comparece CRUZ MARTÍN LÓPEZ.'));
        $this->assertSame(['AMPARO', 'GARCÍA'], $values('DOÑA AMPARO GARCÍA comparece.'));
    }

    public function test_the_d_of_sr_d_is_not_a_surname(): void
    {
        $spans = SpanResolver::resolve((new PersonNameRecognizer())->detect('El Sr. D. Luis Fernando de Castro firma.'));

        $this->assertSame(['Luis', 'Fernando', 'Castro'], array_map(fn ($span) => $span->value, $spans));
    }

    public function test_a_name_never_crosses_a_line_break(): void
    {
        $recognizer = new PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        // A heading, a role or a place on the next line ends the name.
        $this->assertSame(['Raquel', 'Blázquez', 'Martín'], $values("D.ª Raquel Blázquez Martín\nEn Madrid, a 21 de julio de 2025."));
        $this->assertSame(['Jorge', 'Rodriguez', 'Simon', 'Ana', 'Ruiz'], $values("Procurador/a: Jorge Rodriguez Simon\nAbogado/a: Ana Ruiz"));
        $this->assertSame(['Ana', 'Pérez'], $values("Firmado: Ana Pérez\nLey de Enjuiciamiento Civil"));

        // A name can start on the line that ends the previous one.
        $this->assertSame(['Juan', 'Pérez', 'Ana', 'López'], $values("Juan Pérez\nAna López"));

        // Short lines are not wrapped prose: the name keeps only its first line.
        $this->assertSame(['Juan', 'García'], $values("Juan García\nLópez Ruiz comparece."));
    }

    public function test_a_name_gets_every_word_even_after_words_that_open_the_sentence(): void
    {
        $recognizer = new PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        $this->assertSame(['JUAN', 'GARCÍA', 'LÓPEZ'], $values('EL ACUSADO JUAN GARCÍA LÓPEZ.'));
        $this->assertSame(['Juan', 'García', 'Pérez', 'López'], $values('Comparece Juan García Pérez López.'));
    }

    public function test_a_given_name_after_san_or_santa_is_a_saint_or_a_place(): void
    {
        $recognizer = new PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        $this->assertSame([], $values('Vive en San Fernando de Henares, en Santa María del Camí y en Sant Joan Despí.'));
        $this->assertSame([], $values('SAN FERNANDO DE HENARES'));
        $this->assertSame(['Juan', 'Martín'], $values('Comparece Juan San Martín.'));
    }

    public function test_a_name_on_each_line_is_found_on_every_line(): void
    {
        $recognizer = new PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        $this->assertSame(
            ['Antonio', 'García', 'Martínez', 'Manuel', 'Almenar', 'Belenguer'],
            $values("Antonio García Martínez\nManuel Almenar Belenguer"),
        );
    }

    public function test_an_honorific_on_the_next_line_starts_a_name_of_its_own(): void
    {
        $spans = SpanResolver::resolve((new PersonNameRecognizer())->detect("Magistrados:\nD. Antonio García Martínez\nD. Manuel\nD.ª Luz\nDña. Pilar Ruiz"));

        $this->assertSame(
            [['Antonio', 'person'], ['García', 'surname'], ['Martínez', 'surname'], ['Manuel', 'person'], ['Luz', 'person'], ['Pilar', 'person'], ['Ruiz', 'surname']],
            array_map(fn ($span) => [$span->value, $span->type], $spans),
        );
    }

    public function test_a_surname_wrapped_inside_prose_stays_in_the_name(): void
    {
        $recognizer = new PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        $prose = "En la ciudad de Barcelona, ante el tribunal, comparece como demandante D. Albert Bosque\n"
            . "Alberich, que firma, asistido por la procuradora de los tribunales doña Sandra Osorio\n"
            . "Alonso, en nombre de la entidad demandada, y por la letrada doña Marta Cànaves\n"
            . "Bertos. Todos ellos manifiestan su conformidad con lo acordado en la vista celebrada.";

        $this->assertSame(
            ['Albert', 'Bosque', 'Alberich', 'Sandra', 'Osorio', 'Alonso', 'Marta', 'Cànaves', 'Bertos'],
            $values($prose),
        );

        // An extract that starts in the middle of a line: the next line shows the width.
        $this->assertSame(
            ['Albert', 'Bosque', 'Alberich'],
            $values("la dirección letrada de D. Albert Bosque\nAlberich, de Unive Abogados, S.L.P., contra la sentencia 1306/2019, de 2 de julio, dictada por la sección"),
        );

        // A short line in a document is not an extract, even before a long one.
        $this->assertSame(
            ['Juan', 'García'],
            $values("Resolución de 3 de mayo.\nFirma: Juan García\nMadrid, a 3 de mayo de 2025, en presencia del notario que suscribe y da fe."),
        );

        // A label after the wrapped word, or a short line, ends the name there.
        $label = "En la ciudad de Barcelona, ante el tribunal, comparece como demandante D. Albert Bosque\n"
            . "Alberich: que firma, asistido por la procuradora de los tribunales doña Sandra Osorio\n"
            . "Madrid, en nombre de la entidad demandada, y por la letrada doña Marta Cànaves Bertos.\n"
            . "Firma Ana Ruiz\nLópez";

        $this->assertSame(['Albert', 'Bosque', 'Sandra', 'Osorio', 'Madrid', 'Marta', 'Cànaves', 'Bertos', 'Ana', 'Ruiz'], $values($label));
    }

    public function test_function_words_are_never_name_words(): void
    {
        $es = new PersonNameRecognizer();
        $en = new \EduLazaro\Laranon\Recognizers\En\PersonNameRecognizer();
        $values = fn ($recognizer, string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        // Each recognizer applies the grammar of its own language.
        $this->assertSame([], $values($es, 'Esta Sala ha visto el recurso.'));
        $this->assertSame([], $values($es, 'Ese Tribunal estimó el recurso.'));
        $this->assertSame([], $values($en, 'This Court has seen the appeal.'));

        $this->assertSame(['Juan', 'García'], $values($es, 'Si Juan García no comparece.'));
        $this->assertSame(['Juan', 'García'], $values($es, 'Firma Juan García Sobre la base de lo expuesto.'));
        $this->assertSame(['MARÍA', 'LÓPEZ', 'GARCÍA', 'JUAN', 'PÉREZ'], $values($es, 'MARÍA LÓPEZ GARCÍA CONTRA JUAN PÉREZ'));
        $this->assertSame(['JUAN', 'GARCÍA'], $values($es, 'CONDENO A JUAN GARCÍA AL PAGO DE LAS COSTAS.'));

        // Every class, not only the words seen so far.
        $this->assertSame([], $values($es, 'Dicha Sala acuerda. Ambos Cónyuges firman.'));
        $this->assertSame([], $values($en, 'Per J. K. Rowling. Both Spouses sign.'));

        // A function word that is also a frequent given name is still a name.
        $this->assertSame(['Ella', 'Fitzgerald'], $values($en, 'Ella Fitzgerald sang.'));
        $this->assertSame(['PEDRO', 'RUIZ'], $values($es, 'ABSUELVO A PEDRO RUIZ TODOS LOS CARGOS.'));
    }

    public function test_the_english_recognizer_leaves_spanish_particles_to_the_spanish_one(): void
    {
        $en = new \EduLazaro\Laranon\Recognizers\En\PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($en->detect($text)));

        $this->assertSame([], $values('La Dirección General de Seguridad Jurídica.'));
        $this->assertSame([], $values('El Incremento de Valor de los Terrenos de Naturaleza Urbana.'));
        $this->assertSame([], $values('Régimen General de las Instituciones de Crédito.'));
        $this->assertSame(['Vincent', 'Gogh', 'Leonardo', 'Vinci'], $values('Vincent van Gogh met Leonardo da Vinci.'));
    }

    public function test_french_names_in_both_orders_with_particles_and_honorifics(): void
    {
        $fr = new \EduLazaro\Laranon\Recognizers\Fr\PersonNameRecognizer();
        $typed = fn (string $text) => array_map(fn ($span) => [$span->value, $span->type], SpanResolver::resolve($fr->detect($text)));

        $this->assertSame([['Jean', 'person'], ['DUPONT', 'surname']], $typed('Jean DUPONT signe.'));
        $this->assertSame([['DUPONT', 'surname'], ['Jean', 'person']], $typed('DUPONT Jean signe.'));
        $this->assertSame([['GALL', 'surname'], ['Yann', 'person']], $typed('LE GALL Yann signe.'));
        $this->assertSame(
            [['Charles', 'person'], ['Gaulle', 'surname'], ['Marine', 'person'], ['Pen', 'surname']],
            $typed('Charles de Gaulle et Marine Le Pen.'),
        );
        $this->assertSame([['Durand', 'surname']], $typed('Représenté par Me Durand, avocat.'));
        $this->assertSame([['Martin', 'surname']], $typed('Selon Mme Martin.'));

        // A word in capitals after a name must be a known surname.
        $this->assertSame([['Jean', 'person'], ['DUPONT', 'surname']], $typed('Jean DUPONT SARL.'));
    }

    public function test_french_headings_and_places_are_not_names(): void
    {
        $fr = new \EduLazaro\Laranon\Recognizers\Fr\PersonNameRecognizer();

        foreach ([
            'Le Tribunal judiciaire de Lyon.',
            "Le Conseil d'État et la Cour de cassation.",
            'Saint Louis et Sainte-Marie.',
            'La Rose des vents.',
            'Pour la Cour, Selon le Code civil.',
        ] as $text) {
            $this->assertSame([], SpanResolver::resolve($fr->detect($text)), $text);
        }
    }

    public function test_a_french_given_name_carries_its_gender(): void
    {
        $fr = new \EduLazaro\Laranon\Recognizers\Fr\PersonNameRecognizer();
        $gender = fn (string $text) => SpanResolver::resolve($fr->detect($text))[0]->gender;

        $this->assertSame('m', $gender('Jean Dupont signe.'));
        $this->assertSame('f', $gender('Marie Dupont signe.'));
        $this->assertNull($gender('Dominique Dupont signe.'));
    }

    public function test_y_or_i_joins_surnames_but_not_two_people(): void
    {
        $recognizer = new PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => [$span->value, $span->type], SpanResolver::resolve($recognizer->detect($text)));

        $this->assertSame([['Santiago', 'person'], ['Ramón', 'surname'], ['Cajal', 'surname']], $values('Santiago Ramón y Cajal investigó.'));
        $this->assertSame([['Jordi', 'person'], ['Pujol', 'surname'], ['Soley', 'surname']], $values('Jordi Pujol i Soley signa.'));

        // A given name after the connector opens another person.
        $this->assertSame(
            [['Juan', 'person'], ['Pérez', 'surname'], ['María', 'person'], ['López', 'surname']],
            $values('Comparecen Juan Pérez y María López.'),
        );

        // A word that is not a surname is not joined, and an honorific after "y" starts its own name.
        $this->assertSame([['Juan', 'person'], ['Pérez', 'surname']], $values('Firma Juan Pérez y Compañía.'));
        $this->assertSame([['García', 'surname'], ['López', 'surname']], $values('El Sr. García y Sra. López.'));
        $this->assertSame([], $values('La empresa A. Pérez e Hijos.'));
        $this->assertSame([['Ortega', 'surname'], ['Gasset', 'surname']], $values('Lo escribió el Sr. Ortega y Gasset.'));
    }

    public function test_catalan_names_with_i_dels_and_words_that_are_names(): void
    {
        $ca = new \EduLazaro\Laranon\Recognizers\Ca\PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($ca->detect($text)));

        $this->assertSame(['Jordi', 'Pujol', 'Soley'], $values('Ho signa Jordi Pujol i Soley.'));
        $this->assertSame(['Montserrat', 'Puig', 'Prats'], $values('Compareix la Sra. Montserrat Puig dels Prats.'));
        $this->assertSame(['Joan', 'Garcia', 'Maria', 'Soler'], $values('Van detenir Joan Garcia i Maria Soler.'));
        $this->assertSame(['Llum', 'Vidal'], $values('Llum Vidal signa.'));

        foreach (["Els Mossos d'Esquadra.", 'Per tot això, la Llum de la tarda.', 'Aquest Tribunal acorda.', 'AJUNTAMENT DE BARCELONA'] as $text) {
            $this->assertSame([], $values($text), $text);
        }
    }

    public function test_names_are_multicultural_but_a_rare_foreign_name_is_not_a_word(): void
    {
        $en = new \EduLazaro\Laranon\Recognizers\En\PersonNameRecognizer();
        $es = new PersonNameRecognizer();
        $values = fn ($recognizer, string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        // A frequent Spanish name in an English text, with its gender.
        $this->assertSame(['Begoña', 'Arrizabalaga'], $values($en, 'The witness Begoña Arrizabalaga confirmed it.'));
        $this->assertSame('f', SpanResolver::resolve($en->detect('The witness Begoña Arrizabalaga confirmed it.'))[0]->gender);

        // "General" is a name in the US records, borne by too few to be one in a Spanish text.
        $this->assertSame([], $values($es, 'La Dirección General de Seguridad Jurídica.'));
    }

    public function test_italian_names_in_both_orders_with_particles_and_honorifics(): void
    {
        $it = new \EduLazaro\Laranon\Recognizers\It\PersonNameRecognizer();
        $typed = fn (string $text) => array_map(fn ($span) => [$span->value, $span->type], SpanResolver::resolve($it->detect($text)));

        $this->assertSame([['Mario', 'surname'], ['Rossi', 'surname']], $typed('Il Sig. Mario Rossi ha firmato.'));
        $this->assertSame([['ROSSI', 'surname'], ['Mario', 'person']], $typed('Causa promossa da ROSSI Mario contro la società.'));
        $this->assertSame([['Daniele', 'person'], ['Rossi', 'surname'], ['Alessandro', 'person'], ['Piero', 'surname']], $typed('Daniele De Rossi e Alessandro Del Piero.'));
        $this->assertSame([['Gianluca', 'person'], ['Vialli', 'surname']], $typed('Gianluca Vialli ha segnato.'));

        foreach (['Il Tribunale di Milano.', 'La Corte di Cassazione ha rigettato il ricorso.', 'Il Presidente della Repubblica.', 'La Stella Polare.'] as $text) {
            $this->assertSame([], $typed($text), $text);
        }

        // Andrea is a man's name in Italy and a woman's in Spain: no gender.
        $this->assertNull(SpanResolver::resolve($it->detect('Andrea Bocelli canta.'))[0]->gender);
    }

    public function test_german_names_end_at_a_noun(): void
    {
        $de = new \EduLazaro\Laranon\Recognizers\De\PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($de->detect($text)));

        $this->assertSame(['Peter', 'Müller'], $values('Herr Peter Müller wohnt in Berlin.'));
        $this->assertSame(['Thomas', 'Schmidt'], $values('Thomas Schmidt hat den Vertrag unterzeichnet.'));
        $this->assertSame(['Ursula', 'Leyen'], $values('Ursula von der Leyen spricht.'));
        $this->assertSame(['Angela', 'Merkel'], $values('Frau Dr. Angela Merkel spricht.'));

        // Every noun is capitalized in German: "Geld" is not a surname.
        $this->assertSame([], $values('Gestern hat Peter Geld bekommen.'));
    }

    public function test_dutch_names_with_their_particles(): void
    {
        $nl = new \EduLazaro\Laranon\Recognizers\Nl\PersonNameRecognizer();
        $values = fn (string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($nl->detect($text)));

        $this->assertSame(['Jan', 'Berg'], $values('De heer Jan van der Berg tekent.'));
        $this->assertSame(['Pieter', 'Janssen'], $values('Het beroep van Pieter Janssen is afgewezen.'));
        $this->assertSame([], $values('De rechtbank Amsterdam heeft beslist.'));
    }

    public function test_portuguese_names_with_dom_dona_and_their_particles(): void
    {
        $pt = new \EduLazaro\Laranon\Recognizers\Pt\PersonNameRecognizer();
        $typed = fn (string $text) => array_map(fn ($span) => [$span->value, $span->type], SpanResolver::resolve($pt->detect($text)));

        $this->assertSame([['Maria', 'person'], ['Santos', 'surname']], $typed('A Dona Maria dos Santos assinou.'));
        $this->assertSame([['António', 'person'], ['Ferreira', 'surname'], ['Costa', 'surname']], $typed('O recurso de António Ferreira Costa.'));
        $this->assertSame([], $typed('O Tribunal da Relação do Porto julgou.'));
    }

    public function test_a_name_that_is_a_word_is_the_word_after_an_article(): void
    {
        $es = new PersonNameRecognizer();
        $pt = new \EduLazaro\Laranon\Recognizers\Pt\PersonNameRecognizer();
        $values = fn ($recognizer, string $text) => array_map(fn ($span) => $span->value, SpanResolver::resolve($recognizer->detect($text)));

        $this->assertSame([], $values($es, 'Rumbo a la Rosa de los Vientos.'));
        $this->assertSame([], $values($pt, 'A Graça de Deus.'));
        $this->assertSame(['Rosa', 'Martínez'], $values($es, 'Firma Rosa Martínez.'));
    }

    public function test_d_ordinal_a_is_dona_not_an_initial(): void
    {
        $recognizer = new PersonNameRecognizer();

        $full = [['Raquel', 'person'], ['Blázquez', 'surname'], ['Martín', 'surname']];

        foreach ([
            'Firmado: D.ª Raquel Blázquez Martín.' => $full,
            'Comparece Dª Raquel Blázquez Martín.' => $full,
            'Comparece D.ª Raquel.' => [['Raquel', 'person']],
        ] as $text => $expected) {
            $spans = SpanResolver::resolve($recognizer->detect($text));

            $this->assertSame($expected, array_map(fn ($span) => [$span->value, $span->type], $spans), $text);
        }
    }

    public function test_a_given_name_carries_its_gender_from_the_gazetteer(): void
    {
        $recognizer = new PersonNameRecognizer();
        $gender = fn (string $text) => SpanResolver::resolve($recognizer->detect($text))[0]->gender;

        $this->assertSame('f', $gender('Comparece María López.'));
        $this->assertSame('m', $gender('Comparece Juan Pérez.'));

        // Ambiguous in the census: no gender, honorific or not.
        $this->assertNull($gender('Comparece Reyes Martín.'));
        $this->assertNull($gender('Comparece Dña. Cruz Martín.'));
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
