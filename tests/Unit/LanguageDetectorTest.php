<?php

namespace EduLazaro\Laranon\Tests\Unit;

use EduLazaro\Laranon\Support\LanguageDetector;
use PHPUnit\Framework\TestCase;

class LanguageDetectorTest extends TestCase
{
    public function test_it_scores_exactly_like_patrickschur_language_detection(): void
    {
        // Reference scores from patrickschur/language-detection v5.3.1, all languages.
        $detector = LanguageDetector::create();

        $this->assertSame(
            ['es' => 0.5136273864384464, 'gl' => 0.45992100065832786, 'pt-BR' => 0.4170901909150757],
            array_slice($detector->scores('Comparece D. Juan García López, mayor de edad, en nombre y representación de la entidad demandada, y dice que por medio del presente escrito interpone recurso de apelación contra la sentencia.'), 0, 3, true),
        );

        $this->assertSame(
            ['zh-Hans' => 0.2346706424505286, 'zh-Hant' => 0.18934670642450524, 'ja' => 0.09311466522092704],
            array_slice($detector->scores('人人生而自由，在尊严和权利上一律平等。他们赋有理性和良心，并应以兄弟关系的精神相对待。'), 0, 3, true),
        );

        $this->assertCount(116, $detector->scores('Any text at all.'));
    }

    public function test_it_detects_the_language_or_says_it_cannot_tell(): void
    {
        $detector = LanguageDetector::create();

        $this->assertSame('en', $detector->detect('The defendant, John Smith, appeared before the court and stated that he had not received the notice of the hearing.'));
        $this->assertSame('fr', $detector->detect("Le demandeur, M. Jean Dupont, demeurant à Paris, expose que la société défenderesse n'a pas respecté le contrat."));
        $this->assertSame('ca', $detector->detect("L'Ajuntament de Barcelona ha aprovat la modificació del pla urbanístic després de les al·legacions dels veïns."));

        // Too short, mixed, a tie between close variants, or no letters at all.
        $this->assertNull($detector->detect('Madrid'));
        $this->assertNull($detector->detect('Según el contrato, the parties agree to the following terms y condiciones.'));
        $this->assertNull($detector->detect('O réu contestou a ação alegando que o contrato foi rescindido antes do prazo estipulado pelas partes.'));
        $this->assertNull($detector->detect('12345 678 90'));
    }

    public function test_it_chooses_among_the_languages_it_is_given(): void
    {
        $detector = LanguageDetector::create(['es', 'en', 'fr']);

        $this->assertSame(['es', 'fr', 'en'], array_keys($detector->scores('Comparece D. Juan García López en nombre de la entidad demandada y dice que interpone recurso.')));
    }

    public function test_only_lowercase_words_tell_a_language(): void
    {
        $detector = LanguageDetector::create(['es', 'fr']);

        // A capitalized word can be part of a name: "Sans" in Jordi Sans Vidal.
        $this->assertSame([], $detector->wordCounts('Jordi Sans Vidal'));
        $this->assertSame(['fr' => 2], $detector->wordCounts('Il est parti sans'));
    }
}
