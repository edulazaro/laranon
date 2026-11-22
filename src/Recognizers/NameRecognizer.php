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
    /**
     * Joins the parts of a hyphenated name (Pérez-Reverte): the ASCII hyphen,
     * U+2010 and the non-breaking U+2011. For patterns with the u modifier.
     */
    protected const HYPHEN = '[-\x{2010}\x{2011}]';

    /** @var array<string, true>|null */
    protected ?array $firstNames = null;

    /** @var array<string, true>|null */
    protected ?array $lastNames = null;

    /** @var array<string, true>|null */
    protected ?array $ambiguousNames = null;

    /** @var array<string, string>|null */
    protected ?array $genders = null;

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
     * Gazetteer file mapping given names to 'f' or 'm', or null when the
     * locale has none. A name not in it, or ambiguous (Cruz, Reyes), gets no
     * gender. The gender belongs to the word, like its token: an honorific
     * does not set it, since "Dña. Cruz" and "D. Cruz" share the word, and
     * the honorific stays in cleartext anyway.
     */
    protected function gendersFile(): ?string
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
     * Regex matching candidate all-caps name sequences (2+ words, "JUAN
     * GARCÍA LÓPEZ"), or null when the locale does not look for them. They
     * pass a stricter gate than namePattern(): see resolveUpperCandidate().
     */
    protected function upperNamePattern(): ?string
    {
        return null;
    }

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

    /**
     * Every type this recognizer emits: the given name and each surname word.
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        return ['person', 'surname'];
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

        $upper = $this->upperNamePattern();

        if ($upper !== null && preg_match_all($upper, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$value, $offset]) {
                array_push($spans, ...$this->resolveUpperCandidate($value, $offset));
            }
        }

        return $spans;
    }

    /**
     * An all-caps run is a name only when it starts with a known given name
     * AND ends with a known surname: headings are written in capitals too
     * ("TRIBUNAL SUPREMO", "SEGUNDO MOTIVO"), and the double gate is what
     * keeps them out (mixed case only needs the given name). Leading words
     * that are not a given name ("COMPARECE") and trailing words that are not
     * a surname are dropped first.
     *
     * @return array<int, Span>
     */
    protected function resolveUpperCandidate(string $value, int $offset): array
    {
        preg_match_all('/\S+/u', $value, $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0];

        $start = 0;
        $end = count($tokens) - 1;

        while ($start < $end && ! isset($this->firstNames()[$this->parts($tokens[$start][0])[0]])) {
            $start++;
        }

        while ($end > $start && ! $this->isSurname($tokens[$end][0])) {
            $end--;
        }

        if ($end <= $start) {
            return [];
        }

        $relativeStart = $tokens[$start][1];
        $relativeEnd = $tokens[$end][1] + strlen($tokens[$end][0]);
        $trimmed = substr($value, $relativeStart, $relativeEnd - $relativeStart);

        return $this->splitNameWords($trimmed, $offset + $relativeStart, 0.9);
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

        while (count($tokens) - $start >= 2 && ! isset($this->firstNames()[$this->parts($tokens[$start][0])[0]])) {
            $start++;
        }

        if (count($tokens) - $start < 2) {
            return [];
        }

        $first = $this->parts($tokens[$start][0])[0];
        $lastToken = $tokens[array_key_last($tokens)];
        $surnameConfirmed = $this->isSurname($lastToken[0]);

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
     * After a given-name honorific or a bare mention, the first word is the
     * given name and the rest surnames (D. Juan Pérez, Mr. James Miller). After
     * a SURNAME honorific (Sr., Sra., Mr., Mrs.) EVERY word is a surname, one or
     * many (Sr. García, Sr. Pérez López). With a single bare word $loneWordType
     * decides: 'surname' after a surname honorific, 'person' otherwise (Dña. Luz).
     *
     * The given name carries the gazetteer's gender, if the name is in it.
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
        // A surname honorific (Sr., Sra., Mr., Mrs.) makes EVERY word a surname,
        // even several ("Sr. Pérez López" = two surnames). Otherwise the first
        // word is the given name (or, alone, whatever $loneWordType says).
        $type = $loneWordType === 'surname'
            ? 'surname'
            : (count($words) === 1 ? $loneWordType : 'person');

        foreach ($words as [$word, $relative]) {
            $gender = $type === 'person' ? ($this->genders()[$this->parts($word)[0]] ?? null) : null;

            $spans[] = new Span($offset + $relative, strlen($word), $type, $word, $confidence, $gender);

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
     * @return array<string, string> folded given name => 'f' or 'm'
     */
    protected function genders(): array
    {
        if ($this->genders === null) {
            $file = $this->gendersFile();
            $path = ($this->dataPath ?? dirname(__DIR__, 2) . '/data') . '/' . $file;

            $this->genders = $file && is_file($path) ? require $path : [];
        }

        return $this->genders;
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
     * and are unaffected. "San", "Santa" and "Santo" open place and saint
     * names (San Sebastián, Santa Cruz) far more often than a person's.
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
            'san', 'santa', 'santo',
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

    /**
     * The folded parts of a word. The gazetteers list single words, so a
     * hyphenated one is looked up by its parts: the first gates a given name
     * (Jean-Pierre), any of them confirms a surname (Pérez-Reverte).
     *
     * @return array<int, string>
     */
    protected function parts(string $word): array
    {
        return array_map(fn (string $part) => $this->fold($part), preg_split('/' . self::HYPHEN . '/u', $word));
    }

    protected function isSurname(string $word): bool
    {
        foreach ($this->parts($word) as $part) {
            if (isset($this->lastNames()[$part])) {
                return true;
            }
        }

        return false;
    }
}
