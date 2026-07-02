<?php

namespace EduLazaro\Laranon\Tests\Feature;

use EduLazaro\Laranon\Tests\TestCase;

class CorpusTest extends TestCase
{
    public function test_spanish_corpus_recall(): void
    {
        $spans = $this->app->make('laranon')->scan(
            (string) file_get_contents(__DIR__ . '/../fixtures/corpus-es.txt'),
        );

        $types = array_count_values(array_map(fn ($s) => $s->type, $spans));

        $this->assertArrayHasKey('dni', $types);
        $this->assertArrayHasKey('nie', $types);
        $this->assertArrayHasKey('iban', $types);
        $this->assertArrayHasKey('email', $types);
        $this->assertArrayHasKey('phone', $types);
        $this->assertArrayHasKey('person', $types);
        $this->assertArrayHasKey('surname', $types);
        $this->assertArrayHasKey('case_ref', $types);
    }

    public function test_english_corpus_recall(): void
    {
        $spans = $this->app->make('laranon')->scan(
            (string) file_get_contents(__DIR__ . '/../fixtures/corpus-en.txt'),
        );

        $types = array_count_values(array_map(fn ($s) => $s->type, $spans));

        $this->assertArrayHasKey('ssn', $types);
        $this->assertArrayHasKey('email', $types);
        $this->assertArrayHasKey('phone', $types);
        $this->assertArrayHasKey('person', $types);
        $this->assertArrayHasKey('surname', $types);
    }
}
