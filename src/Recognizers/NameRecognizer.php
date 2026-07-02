<?php

namespace EduLazaro\Laranon\Recognizers;

use EduLazaro\Laranon\Contracts\Recognizer;
use EduLazaro\Laranon\Support\Span;
use Illuminate\Support\Str;

/**
 * Person-name detection without ML: honorific triggers (D., Sr., Mr., Dr...)
 * plus gazetteer-gated capitalized sequences (a sequence only counts when its
 * first token is a known given name). Locale subclasses provide the patterns
 * and dictionaries.
 */
abstract class NameRecognizer implements Recognizer
{
    /** @var array<string, true>|null */
    protected ?array $firstNames = null;

    /** @var array<string, true>|null */
    protected ?array $lastNames = null;

    /** @var array<string, true>|null */
    protected ?array $ambiguousNames = null;

    public function __construct(protected ?string $dataPath = null)
    {
    }

    /**
     * Regex whose first capturing group is the name following a given-name
     * honorific (D., Dña., Don, Doña...), or null when the locale has none.
     * The first captured word is typed 'person'.
     */
    protected function honorificPattern(): ?string
    {
        return null;
    }

    /**
     * Regex whose first capturing group is the name following a surname
     * honorific (Sr., Sra., Dr., Mr., Mrs...), or null when the locale has
     * none. Convention puts a surname after these titles ("Sr. García",
     * "Mr. Smith"), so every captured word is typed 'surname'. This is the
     * reliable signal: gazetteer membership is not (census data contains
     * artifacts like "garcia" listed among given names).
     */
    protected function surnameHonorificPattern(): ?string
    {
        return null;
    }

    /**
     * Regex matching candidate capitalized name sequences (2+ words).
     */
    abstract protected function namePattern(): string;

    /**
     * Gazetteer file with given names, relative to the data path.
     */
    abstract protected function firstNamesFile(): string;

    /**
     * Gazetteer file with surnames, or null when the locale has none.
     */
    protected function lastNamesFile(): ?string
    {
        return null;
    }

    /**
     * Gazetteer of given names that are also common nouns/adjectives
     * ("Luz", "Rose", "Faith"...), or null when the locale has none. A word
     * in this list only gates a match when a surname is also confirmed, so
     * bare "Luz" is left alone but "Luz Martínez" is not.
     */
    protected function ambiguousNamesFile(): ?string
    {
        return null;
    }

    public function type(): string
    {
        return 'person';
    }

    public function detect(string $text): array
    {
        $spans = [];

        $honorifics = [
            ['pattern' => $this->honorificPattern(), 'loneWordType' => 'person'],
            ['pattern' => $this->surnameHonorificPattern(), 'loneWordType' => 'surname'],
        ];

        foreach ($honorifics as $honorific) {
            if ($honorific['pattern'] === null) {
                continue;
            }

            if (! preg_match_all($honorific['pattern'], $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as [$value, $offset]) {
                if ($value !== '') {
                    array_push($spans, ...$this->splitNameWords($value, $offset, 0.95, $honorific['loneWordType']));
                }
            }
        }

        if (preg_match_all($this->namePattern(), $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$value, $offset]) {
                array_push($spans, ...$this->resolveCandidate($value, $offset));
            }
        }

        return $spans;
    }

    /**
     * A greedy namePattern match can start one word too early: a
     * capitalized sentence-initial verb immediately followed by a real name
     * ("Comparece María López") gets swallowed into a single candidate that
     * then fails the gazetteer gate on its first (non-name) word, silently
     * discarding the real name embedded right after it. Rather than give up,
     * progressively drop the leading word and retry with the shorter
     * candidate, recomputing its exact position in the original text.
     *
     * @return array<int, Span>
     */
    protected function resolveCandidate(string $value, int $offset): array
    {
        preg_match_all('/\S+/u', $value, $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0];

        $start = 0;

        while (count($tokens) - $start >= 2 && ! isset($this->firstNames()[$this->fold($tokens[$start][0])])) {
            $start++;
        }

        if (count($tokens) - $start < 2) {
            return [];
        }

        $first = $this->fold($tokens[$start][0]);
        $lastToken = $tokens[array_key_last($tokens)];
        $last = $this->fold($lastToken[0]);
        $surnameConfirmed = isset($this->lastNames()[$last]);

        if (isset($this->ambiguousNames()[$first]) && ! $surnameConfirmed) {
            // "Luz"/"Rose"/"Faith" read as the common word far more often
            // than as a bare, surname-less mention of the person.
            return [];
        }

        $relativeStart = $tokens[$start][1];
        $relativeEnd = $lastToken[1] + strlen($lastToken[0]);
        $trimmed = substr($value, $relativeStart, $relativeEnd - $relativeStart);

        return $this->splitNameWords($trimmed, $offset + $relativeStart, $surnameConfirmed ? 0.9 : 0.85);
    }

    /**
     * Every word of a matched name becomes its own INDEPENDENT span: the
     * given name (type 'person') and each surname word (type 'surname').
     * Replacements are keyed per word, never per person: "María" always
     * yields the same token wherever it appears, so no identity resolution
     * is ever attempted. "María López" and "María Fernández" naturally share
     * the given-name token and differ in the surname token, which is exactly
     * the information a human reader has. Grammatical particles (de, la,
     * del...) stay in cleartext: alone they identify nobody and they keep
     * the structure of the name readable.
     *
     * With several words, the first is the given name and the rest surnames
     * (D. Juan Pérez, Mr. James Miller). With a SINGLE word, position says
     * nothing, so $loneWordType decides: 'surname' after a surname honorific
     * (Sr. García, Mr. Smith), 'person' otherwise (Dña. Luz).
     *
     * @return array<int, Span>
     */
    protected function splitNameWords(string $value, int $offset, float $confidence, string $loneWordType = 'person'): array
    {
        preg_match_all('/\S+/u', $value, $matches, PREG_OFFSET_CAPTURE);

        $particles = array_fill_keys($this->articleBlocklist(), true);

        $words = [];

        foreach ($matches[0] as [$word, $relative]) {
            if (! isset($particles[$this->fold($word)])) {
                $words[] = [$word, $relative];
            }
        }

        $spans = [];
        $type = count($words) === 1 ? $loneWordType : 'person';

        foreach ($words as [$word, $relative]) {
            $spans[] = new Span($offset + $relative, strlen($word), $type, $word, $confidence);

            $type = 'surname';
        }

        return $spans;
    }

    /**
     * @return array<string, true>
     */
    protected function firstNames(): array
    {
        return $this->firstNames ??= $this->loadNames($this->firstNamesFile());
    }

    /**
     * @return array<string, true>
     */
    protected function lastNames(): array
    {
        if ($this->lastNames === null) {
            $file = $this->lastNamesFile();
            $this->lastNames = $file ? $this->loadNames($file) : [];
        }

        return $this->lastNames;
    }

    /**
     * @return array<string, true>
     */
    protected function ambiguousNames(): array
    {
        if ($this->ambiguousNames === null) {
            $file = $this->ambiguousNamesFile();
            $this->ambiguousNames = $file ? $this->loadNames($file) : [];
        }

        return $this->ambiguousNames;
    }

    /**
     * Grammatical particles and honorific titles that must never gate a
     * match or be tokenized as name words, even when present in the
     * gazetteer data (census artifacts include "el", "don" or "dona" as
     * given names via compound-name splitting). In legal text, capitalized
     * institutional openers ("El Tribunal Supremo") and titles ("Doña Luz")
     * are far more frequent than bare, honorific-less mentions of such
     * names, so excluding them trades a small recall loss for a large
     * precision gain. The honorific patterns do not consult the gazetteer
     * and are unaffected.
     *
     * @return array<int, string>
     */
    protected function articleBlocklist(): array
    {
        return [
            'el', 'la', 'los', 'las', 'lo', 'un', 'una', 'unos', 'unas', 'de', 'del', 'y', 'e',
            'the', 'a', 'an',
            'don', 'dona', 'sr', 'sra', 'srta', 'dr', 'dra', 'ldo', 'lda', 'lcdo', 'lcda',
            'mr', 'mrs', 'ms', 'miss', 'prof', 'sir', 'lady',
        ];
    }

    /**
     * @return array<string, true>
     */
    protected function loadNames(string $file): array
    {
        $path = ($this->dataPath ?? dirname(__DIR__, 2) . '/data') . '/' . $file;

        $names = is_file($path) ? require $path : [];
        $names = array_diff($names, $this->articleBlocklist());

        return array_fill_keys($names, true);
    }

    protected function fold(string $value): string
    {
        return Str::ascii(mb_strtolower($value));
    }
}
