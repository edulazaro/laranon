<?php

namespace EduLazaro\Laranon\Recognizers;

use EduLazaro\Laranon\Contracts\LanguageScoped;
use EduLazaro\Laranon\Contracts\Recognizer;
use EduLazaro\Laranon\Support\Span;
use Illuminate\Support\Str;

/**
 * Person-name detection without ML: honorific triggers (D., Sr., Mr., Dr...)
 * plus gazetteer-gated capitalized sequences (a sequence only counts when its
 * first token is a known given name). Locale subclasses provide the patterns
 * and dictionaries.
 */
abstract class NameRecognizer implements Recognizer, LanguageScoped
{
    /**
     * Joins the parts of a hyphenated name (Pérez-Reverte): the ASCII hyphen,
     * U+2010 and the non-breaking U+2011. For patterns with the u modifier.
     */
    protected const HYPHEN = '[-\x{2010}\x{2011}]';

    /**
     * A capitalized name word, for patterns with the u modifier: one or more
     * parts that each start with a capital and go on in lowercase, so any
     * inner capital is covered (McKellen, MacArthur, DiCaprio, DeVito,
     * LeBlanc, DuPont, VanDyke), with straight or typographic apostrophes
     * (O'Brien, O’Neill, D'Angelo, Dell'Orto) and hyphenated parts
     * (Pérez-Reverte). A run of capitals alone (ABC) is not a name word, and
     * a name word never ends in an apostrophe: in "‘Juan García’" the quote
     * closes the name.
     */
    protected const NAME_WORD = '(?:\p{Lu}[\p{Ll}\'’]+)+(?:[-\x{2010}\x{2011}](?:\p{Lu}[\p{Ll}\'’]+)+)*(?<![\'’])';

    /**
     * A given-name initial ("F.", and the Spanish "M.ª"/"Mª" for María), for
     * patterns with the u modifier. It stays in cleartext.
     */
    protected const INITIAL = '\p{Lu}(?:\.ª?|ª)';

    /**
     * A name word opened by a lowercase prefix: d'Ors, dell'Acqua, al-Assad,
     * el-Sisi. Never the first word of a name.
     */
    protected const PREFIXED_WORD = '(?:(?:d|l|dell|dall)[\'’]|(?:al|el)-)' . self::NAME_WORD;

    /**
     * Lowercase particles between the words of a name, for patterns with the
     * u modifier: Spanish (de la Cruz), Portuguese (da Silva, dos Santos),
     * Italian (di Stefano, della Rovere), French (du Pont, le Pen), Dutch and
     * German (van der Waals, von Braun) and Arabic or Hebrew (bin Salman, ibn
     * Battuta, ben Gurion). They stay in cleartext. "al" and "el" alone are
     * left out: "García al Juzgado" is a sentence, not a name.
     */
    protected const PARTICLES = 'della|dello|delle|degli|dei|dels|del|des|de(?:\s+l(?:a|os|as))?|du|das|da|dos|do|di|la|le|lo'
        . '|van(?:\s+de[rn]?)?|von(?:\s+der)?|der|den|ten|ter|bint|bin|ibn|ben';

    /**
     * The single words of PARTICLES, kept in cleartext when written in
     * lowercase. "Ben" or "Van" with a capital stay name words.
     */
    protected const PARTICLE_WORDS = [
        'della', 'dello', 'delle', 'degli', 'dei', 'dels', 'des', 'du', 'das', 'da', 'dos', 'do', 'di', 'le', 'lo',
        'van', 'von', 'der', 'den', 'ten', 'ter', 'bint', 'bin', 'ibn', 'ben',
    ];

    /** @var array<string, true>|null */
    protected ?array $firstNames = null;

    /** @var array<string, true>|null */
    protected ?array $lastNames = null;

    /** @var array<string, true>|null */
    protected ?array $ambiguousNames = null;

    /** @var array<string, true>|null */
    protected ?array $commonNames = null;

    /** The text detect() is reading, and the width of its full lines. */
    protected string $text = '';

    protected ?int $lineWidth = null;

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

    /**
     * Gazetteer of the given names common enough to open a name in capitals,
     * or null to let any known given name do it. In capitals there is no case
     * to go by, and a rare given name that is also a word opens headings
     * ("REY DE ESPAÑA") far more often than a person's name.
     */
    protected function commonNamesFile(): ?string
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
        $this->text = $text;
        $this->lineWidth = $this->joinsWrappedLines() ? $this->typicalLineWidth($text) : null;

        $spans = [];

        $honorifics = [
            ['pattern' => $this->honorificPattern(), 'loneWordType' => 'person'],
            ['pattern' => $this->surnameHonorificPattern(), 'loneWordType' => 'surname'],
        ];

        foreach ($honorifics as $honorific) {
            if ($honorific['pattern'] === null) {
                continue;
            }

            // The search goes on right after the part of a match kept as a
            // name, not after the match: what a match took past the end of the
            // name ("García Martínez⏎D. Manuel") is read again.
            $position = 0;

            while (preg_match($honorific['pattern'], $text, $match, PREG_OFFSET_CAPTURE, $position) === 1) {
                [$value, $offset] = $match[1];
                $kept = $value === '' ? 0 : $this->nameLength($value, $offset, $honorific['loneWordType'] === 'surname');

                if ($kept > 0) {
                    array_push($spans, ...$this->splitNameWords(substr($value, 0, $kept), $offset, 0.95, $honorific['loneWordType']));
                }

                $position = max($offset + $kept, $match[0][1] + 1);
            }
        }

        array_push($spans, ...$this->scanNames(
            $this->namePattern(),
            fn (string $word) => $this->opensName($word),
            fn (string $value, int $offset) => $this->resolveCandidate($value, $offset),
        ));

        $upper = $this->upperNamePattern();

        if ($upper !== null) {
            array_push($spans, ...$this->scanNames(
                $upper,
                fn (string $word) => $this->opensUpperName($word),
                fn (string $value, int $offset) => $this->resolveUpperCandidate($value, $offset),
            ));
        }

        return $spans;
    }

    /**
     * Runs a name pattern over the text so that no name is lost to what a
     * match took around it. A match that starts before its first given name
     * ("EL ACUSADO JUAN GARCÍA", "Comparece Juan García Pérez") is tried again
     * from that name, so the name gets every word the pattern allows
     * ("JUAN GARCÍA LÓPEZ"). A match that runs past the end of the name (a
     * line break, a function word) goes on from that end, so a name that
     * starts there is found ("Antonio García Martínez⏎Manuel Almenar").
     *
     * @param callable(string): bool $opens whether a word opens a name
     * @param callable(string, int): array<int, Span> $resolve
     * @return array<int, Span>
     */
    protected function scanNames(string $pattern, callable $opens, callable $resolve): array
    {
        $spans = [];
        $position = 0;

        while (preg_match($pattern, $this->text, $match, PREG_OFFSET_CAPTURE, $position) === 1) {
            [$value, $offset] = $match[0];
            preg_match_all('/\S+/u', $value, $words, PREG_OFFSET_CAPTURE);

            $opener = null;

            foreach ($words[0] as $i => [$word]) {
                if ($opens($word)) {
                    $opener = $i;
                    break;
                }
            }

            if ($opener === null) {
                $position = $offset + strlen($value);
            } elseif ($opener > 0) {
                $position = $offset + $words[0][$opener][1];
            } else {
                $kept = $this->nameLength($value, $offset);

                if (! $this->followsSaint($offset)) {
                    array_push($spans, ...$resolve(substr($value, 0, $kept), $offset));
                }

                $position = $offset + max($kept, 1);
            }
        }

        return $spans;
    }

    /**
     * An all-caps run is a name only when it starts with a common given name
     * AND ends with a known surname: headings are written in capitals too
     * ("TRIBUNAL SUPREMO", "SEGUNDO MOTIVO", "REY DE ESPAÑA"), and the double
     * gate is what keeps them out (mixed case only needs a known given
     * name). Leading words that are not a given name ("COMPARECE") and
     * trailing words that are not a surname are dropped first.
     *
     * @return array<int, Span>
     */
    protected function resolveUpperCandidate(string $value, int $offset): array
    {
        preg_match_all('/\S+/u', $value, $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0];

        $start = 0;
        $end = count($tokens) - 1;

        while ($start < $end && ! $this->opensUpperName($tokens[$start][0])) {
            $start++;
        }

        $rest = substr($value, $tokens[$start][1]);
        $restOffset = $offset + $tokens[$start][1];
        $keep = $this->nameLength($rest, $restOffset);

        if ($keep < strlen($rest)) {
            preg_match('/\S/u', $rest, $next, PREG_OFFSET_CAPTURE, $keep);

            return [
                ...$this->resolveUpperCandidate(substr($rest, 0, $keep), $restOffset),
                ...$this->resolveUpperCandidate(substr($rest, $next[0][1]), $restOffset + $next[0][1]),
            ];
        }

        while ($end > $start && ! $this->isSurname($tokens[$end][0])) {
            $end--;
        }

        if ($end <= $start) {
            return [];
        }

        // An ambiguous given name in capitals ("AMPARO", "CRUZ", "MERCEDES")
        // opens a heading or a company ("CRUZ ROJA") far more often than a
        // name with a single surname: it needs two.
        $surnames = array_filter(
            array_slice($tokens, $start + 1, $end - $start),
            fn (array $token) => $this->isSurname($token[0]),
        );

        if (isset($this->ambiguousNames()[$this->parts($tokens[$start][0])[0]]) && count($surnames) < 2) {
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

        while (count($tokens) - $start >= 2 && ! $this->opensName($tokens[$start][0])) {
            $start++;
        }

        if (count($tokens) - $start < 2) {
            return [];
        }

        // Past a line break that ends the name, the rest is a candidate of
        // its own: a name can still start there ("Juan Pérez⏎Ana López").
        $rest = substr($value, $tokens[$start][1]);
        $restOffset = $offset + $tokens[$start][1];
        $keep = $this->nameLength($rest, $restOffset);

        if ($keep < strlen($rest)) {
            preg_match('/\S/u', $rest, $next, PREG_OFFSET_CAPTURE, $keep);

            return [
                ...$this->resolveCandidate(substr($rest, 0, $keep), $restOffset),
                ...$this->resolveCandidate(substr($rest, $next[0][1]), $restOffset + $next[0][1]),
            ];
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
     * Whether the name at $offset comes right after San, Santa, Santo, Sant,
     * Saint or Sainte: a given name there is a saint or a place ("San
     * Fernando de Henares", "Santa María del Camí", "Sant Joan Despí",
     * "Saint Louis"), not a person.
     */
    protected function followsSaint(int $offset): bool
    {
        $before = mb_strcut($this->text, max(0, $offset - 8), min($offset, 8));

        return preg_match('/(?<!\p{L})(?i:san|santa|santo|sant|saint|sainte)\h+$/u', $before) === 1;
    }

    /**
     * How much of a candidate, found at $offset of the text, is still one
     * name, in bytes: up to its first line break or function word. What
     * follows a name on the next line is a heading, a role or a place far
     * more often than the rest of the name ("Martín⏎En Madrid", "Simon⏎
     * Abogado"), and the surname lists cannot tell them apart: the US census
     * lists Abogado, Madrid and Ley as surnames. The exception is a line
     * wrapped inside prose (see wrappedLength()). A function word ("Juan
     * García Sobre la base") is never part of a name. A "y", "e" or "i"
     * joins two surnames ("Santiago Ramón y Cajal", "Jordi Pujol i Soley")
     * only after a surname, which needs a given name before it unless
     * $surnamesFirst (a surname honorific: "Sr. Ortega y Gasset"), and only
     * when the word after it is a known surname and not a given name: "Juan
     * Pérez y María López" are two people, "Pérez e Hijos" a company.
     */
    protected function nameLength(string $value, int $offset, bool $surnamesFirst = false): int
    {
        $length = preg_match('/\h*\R/u', $value, $match, PREG_OFFSET_CAPTURE) === 1
            ? $this->wrappedLength($value, $match[0][1], $offset)
            : strlen($value);

        preg_match_all('/\S+/u', substr($value, 0, $length), $matches, PREG_OFFSET_CAPTURE);
        $functionWords = array_fill_keys($this->functionWords(), true);

        foreach (array_slice($matches[0], 1, null, true) as $i => [$word, $relative]) {
            $next = $matches[0][$i + 1][0] ?? null;
            $joinsSurnames = $i >= ($surnamesFirst ? 1 : 2) && $next !== null && $this->isSurname($next) && ! $this->opensName($next);

            if (isset($functionWords[$this->fold($word)]) || (in_array($word, ['y', 'e', 'i'], true) && ! $joinsSurnames)) {
                return $matches[0][$i - 1][1] + strlen($matches[0][$i - 1][0]);
            }
        }

        return $length;
    }

    /**
     * Where a name cut by the line break at $break really ends. A PDF wraps
     * prose at a fixed width, so a full line that ends inside a name ("D.
     * Albert Bosque⏎Alberich, que firma") goes on with the surnames that open
     * the next line. Only when the line is as long as the text's full lines
     * (a heading or a signature is shorter), or, for the first line of the
     * text, which an extract can start in the middle of, the line after it
     * is; the break is a single one, every word taken is a known surname of
     * this locale, and no ":" or "/" follows them (a label such as
     * "Abogado/a:"). Otherwise the name ends at the break.
     */
    protected function wrappedLength(string $value, int $break, int $offset): int
    {
        if ($this->lineWidth === null
            || preg_match('/\G\h*\R\h*/u', $value, $gap, 0, $break) !== 1
            || preg_match('/\G\R/u', $value, $blank, 0, $break + strlen($gap[0])) === 1) {
            return $break;
        }

        $before = substr($this->text, 0, $offset + $break);
        $line = substr($before, max((int) strrpos($before, "\n"), (int) strrpos($before, "\r")));
        $start = $break + strlen($gap[0]);
        preg_match('/\G[^\r\n]*/u', $this->text, $following, 0, $offset + $start);

        $first = strpbrk($before, "\r\n") === false;
        $full = fn (string $line) => mb_strlen(trim($line)) >= 0.8 * $this->lineWidth;

        if (! $full($line) && ! ($first && $full($following[0]))) {
            return $break;
        }

        preg_match('/\G[^\r\n]*/u', $value, $next, 0, $start);
        preg_match_all('/\S+/u', $next[0], $words, PREG_OFFSET_CAPTURE);

        $end = $break;

        foreach ($words[0] as [$word, $relative]) {
            if (! $this->isSurname($word)) {
                break;
            }

            $end = $start + $relative + strlen($word);
        }

        $after = substr($this->text, $offset + $end, 1);

        return $end > $break && $after !== ':' && $after !== '/' ? $end : $break;
    }

    /**
     * The width, in characters, of the text's full lines: the median length
     * of its lines of 40 characters or more, or null when none reaches that,
     * as in a short message with no wrapped prose.
     */
    protected function typicalLineWidth(string $text): ?int
    {
        $lengths = array_values(array_filter(
            array_map(fn (string $line) => mb_strlen(trim($line)), preg_split('/\R/u', $text)),
            fn (int $length) => $length >= 40,
        ));

        if ($lengths === []) {
            return null;
        }

        sort($lengths);

        return $lengths[intdiv(count($lengths), 2)];
    }

    /**
     * Whether a name may run on to the next line when a PDF wraps it (see
     * wrappedLength()). Off unless the locale's surname list is curated
     * enough to tell a surname from a word: the US census list is not.
     */
    protected function joinsWrappedLines(): bool
    {
        return false;
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
        $lowercase = array_fill_keys(self::PARTICLE_WORDS, true);

        $words = [];

        foreach ($matches[0] as [$word, $relative]) {
            $initial = preg_match('/^' . self::INITIAL . '$/u', $word) === 1;

            if (! $initial && ! isset($particles[$this->fold($word)]) && ! isset($lowercase[$word])) {
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
     * @return array<string, true>
     */
    protected function commonNames(): array
    {
        if ($this->commonNames === null) {
            $file = $this->commonNamesFile();
            $this->commonNames = $file ? $this->loadNames($file) : [];
        }

        return $this->commonNames;
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
     * and are unaffected. "San", "Santa", "Santo", "Sant", "Saint" and
     * "Sainte" open place and saint names (San Sebastián, Santa Cruz, Saint
     * Louis) far more often than a person's. The French articles (Le, Les,
     * Du, Des) open French sentences ("Le Tribunal") whatever pack reads
     * them; the price is that the Vietnamese surname Le stays in cleartext.
     *
     * @return array<int, string>
     */
    protected function articleBlocklist(): array
    {
        return [
            'el', 'la', 'los', 'las', 'lo', 'un', 'una', 'unos', 'unas', 'de', 'del', 'y', 'e', 'i',
            'the', 'a', 'an',
            'don', 'dona', 'sr', 'sra', 'srta', 'dr', 'dra', 'ldo', 'lda', 'lcdo', 'lcda',
            'mr', 'mrs', 'ms', 'miss', 'prof', 'sir', 'lady',
            'san', 'santa', 'santo', 'sant', 'saint', 'sainte',
            'le', 'les', 'du', 'des', 'dels',
        ];
    }

    /**
     * The closed classes of Spanish, Catalan, English and French grammar: prepositions,
     * articles and contractions, determiners, possessives, pronouns and
     * conjunctions, plus the forms of "be" and "have". They open sentences
     * and clauses ("Esta Sala", "Si Juan García", "Sobre la base", "Per the
     * agreement"), and the census lists some as names (Esta, Ese, Si, Per)
     * or surnames (Contra, Sobre, En), but a capitalized one beside a name
     * almost always starts the text around it. They are never name words,
     * and a name ends where one appears. The particles of names (de, del,
     * la, le, du, van, da...) are not here, and neither are the words that
     * are also frequent names or surnames: Ella, Elle, Ha, Han, He, Jo, Ma,
     * Mas, Ni, Ou, Quan, Sa, Son, Su, Tu.
     *
     * @return array<int, string>
     */
    protected function functionWords(): array
    {
        return [
            // Spanish prepositions and the contraction "al".
            'ante', 'bajo', 'cabe', 'con', 'contra', 'desde', 'durante', 'en', 'entre', 'hacia', 'hasta',
            'mediante', 'para', 'por', 'segun', 'sin', 'sobre', 'tras', 'versus', 'via', 'al',
            // Spanish determiners and possessives.
            'este', 'esta', 'estos', 'estas', 'ese', 'esa', 'esos', 'esas', 'aquel', 'aquella', 'aquellos',
            'aquellas', 'esto', 'eso', 'aquello', 'mi', 'mis', 'tus', 'sus', 'nuestro', 'nuestra', 'nuestros',
            'nuestras', 'vuestro', 'vuestra', 'vuestros', 'vuestras', 'todo', 'todos', 'toda', 'todas', 'cada',
            'otro', 'otra', 'otros', 'otras', 'mismo', 'misma', 'mismos', 'mismas', 'dicho', 'dicha', 'dichos',
            'dichas', 'tal', 'tales', 'ambos', 'ambas', 'varios', 'varias', 'alguno', 'alguna', 'algunos',
            'algunas', 'ninguno', 'ninguna', 'cualquier', 'cualquiera',
            // Spanish pronouns, relatives and conjunctions.
            'yo', 'ellos', 'ellas', 'nosotros', 'nosotras', 'vosotros', 'vosotras', 'usted', 'ustedes',
            'que', 'como', 'cuando', 'donde', 'cual', 'cuales', 'quien', 'quienes', 'cuyo', 'cuya', 'cuyos',
            'cuyas', 'pero', 'sino', 'porque', 'aunque', 'pues', 'mientras', 'no', 'si', 'ya',
            // Catalan articles, contractions and prepositions.
            'els', 'uns', 'unes', 'als', 'pel', 'pels', 'amb', 'sense', 'fins', 'cap', 'segons', 'durant',
            'mitjancant', 'malgrat',
            // Catalan determiners, possessives, pronouns and conjunctions.
            'aquest', 'aquesta', 'aquests', 'aquestes', 'aquell', 'aquella', 'aquells', 'aquelles', 'meu',
            'meva', 'teu', 'teva', 'seu', 'seva', 'nostre', 'nostra', 'vostre', 'vostra', 'llur', 'llurs',
            'tot', 'tota', 'tots', 'totes', 'altre', 'altra', 'mateix', 'mateixa', 'ell', 'nosaltres',
            'vosaltres', 'ells', 'voste', 'vostes', 'perque', 'com', 'mentre', 'doncs', 'es', 'era', 'va',
            'fou',
            // English prepositions.
            'about', 'above', 'across', 'after', 'against', 'along', 'amid', 'among', 'around', 'as', 'at',
            'before', 'behind', 'below', 'beneath', 'beside', 'besides', 'between', 'beyond', 'by',
            'concerning', 'despite', 'down', 'during', 'except', 'for', 'from', 'in', 'inside', 'into',
            'like', 'near', 'of', 'off', 'on', 'onto', 'out', 'outside', 'over', 'past', 'per', 'regarding',
            'since', 'than', 'through', 'throughout', 'till', 'to', 'toward', 'towards', 'under',
            'underneath', 'unlike', 'until', 'up', 'upon', 'with', 'within', 'without',
            // English determiners, possessives and pronouns.
            'this', 'that', 'these', 'those', 'all', 'any', 'each', 'every', 'some', 'other', 'another',
            'such', 'both', 'either', 'neither', 'my', 'your', 'his', 'her', 'its', 'our', 'their', 'it',
            'we', 'you', 'she', 'they', 'them', 'us', 'him',
            // English conjunctions and relatives, and the forms of be and have.
            'and', 'or', 'but', 'nor', 'yet', 'if', 'because', 'although', 'though', 'while', 'whereas',
            'unless', 'whether', 'when', 'where', 'which', 'who', 'whom', 'whose', 'what', 'why', 'how',
            'is', 'was', 'are', 'were', 'be', 'been', 'has', 'have', 'had',
            // French contractions and articles that are not name particles.
            'au', 'aux', 'un', 'une',
            // French determiners and possessives.
            'ce', 'cet', 'cette', 'ces', 'mon', 'mes', 'ton', 'ta', 'tes', 'ses', 'notre', 'nos', 'votre',
            'vos', 'leur', 'leurs', 'tout', 'tous', 'toute', 'toutes', 'chaque', 'aucun', 'aucune', 'autre',
            'autres', 'meme', 'tel', 'telle', 'tels', 'telles', 'ledit', 'ladite', 'lesdits', 'lesdites',
            // French pronouns, relatives and conjunctions.
            'celui', 'celle', 'ceux', 'celles', 'il', 'ils', 'elles', 'nous', 'vous', 'je', 'qui', 'quoi',
            'dont', 'quand', 'comme', 'et', 'mais', 'donc', 'car',
            // French prepositions, and the forms of etre and avoir.
            'pour', 'par', 'sur', 'sous', 'avec', 'sans', 'dans', 'vers', 'chez', 'contre', 'depuis',
            'pendant', 'selon', 'avant', 'apres', 'malgre', 'parmi',
            'est', 'sont', 'etait', 'ont', 'avait',
        ];
    }

    /**
     * @return array<string, true>
     */
    protected function loadNames(string $file): array
    {
        $path = ($this->dataPath ?? dirname(__DIR__, 2) . '/data') . '/' . $file;

        $names = is_file($path) ? require $path : [];
        $names = array_diff($names, $this->articleBlocklist(), $this->functionWords());

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

    /**
     * Whether a word can open a name: a known given name written with a
     * capital. A lowercase particle never does, even when the census lists
     * it as a given name ("von" in "Wernher von Braun"). A hyphenated one
     * needs a given name in every part (Jean-Pierre, María-José), so a brand
     * that starts with one ("Mercedes-Benz") does not.
     */
    protected function opensName(string $word): bool
    {
        if (preg_match('/^\p{Lu}/u', $word) !== 1) {
            return false;
        }

        foreach ($this->parts($word) as $part) {
            if (! isset($this->firstNames()[$part])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a word in capitals can open a name: a known given name, and a
     * common one when the locale ranks them (see commonNamesFile()).
     */
    protected function opensUpperName(string $word): bool
    {
        return $this->opensName($word)
            && ($this->commonNamesFile() === null || isset($this->commonNames()[$this->parts($word)[0]]));
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
