<?php

namespace EduLazaro\Laranon;

use EduLazaro\Laranon\Contracts\Strategy;
use EduLazaro\Laranon\Contracts\VaultStore;
use EduLazaro\Laranon\Recognizers\Universal\KnownEntitiesRecognizer;
use EduLazaro\Laranon\Streaming\StreamDeanonymizer;
use EduLazaro\Laranon\Support\AnonymizedText;
use EduLazaro\Laranon\Support\LanguageDetector;
use EduLazaro\Laranon\Support\Span;
use EduLazaro\Laranon\Support\SpanResolver;
use EduLazaro\Laranon\Support\TokenMap;
use EduLazaro\Laranon\Support\ValueKey;
use Illuminate\Contracts\Cache\LockProvider;
use InvalidArgumentException;

/**
 * The orchestrator: runs every recognizer over the text, resolves overlaps,
 * replaces matches according to the strategy and keeps the token map so the
 * process can be reversed. Fluent methods return clones, so the container
 * singleton is never mutated.
 */
class Anonymizer
{
    /**
     * How long a scope lock lives if its holder dies mid-call.
     */
    protected const LOCK_SECONDS = 30;

    protected ?string $scope = null;

    /** @var array<int, string>|null */
    protected ?array $only = null;

    /** @var array<int, string>|null */
    protected ?array $except = null;

    /** @var array<int, string>|null languages set with language(), instead of detecting them */
    protected ?array $languages = null;

    protected ?LanguageDetector $detector = null;

    /** Among the languages with a pack only, for each paragraph. */
    protected ?LanguageDetector $packDetector = null;

    /** @var array<int, \EduLazaro\Laranon\Contracts\Recognizer> */
    protected array $extraRecognizers = [];

    /** @var array<int, array{value: string, type: string, id: int}> */
    protected array $presets = [];

    /** @var array<int, string> known-entity types whose written forms share a token */
    protected array $variantTypes = [];

    /** @var array<int, array{type: string, value: string}> known entities given 'variants' => false */
    protected array $literalEntities = [];

    protected Strategy|string|null $currentStrategy = null;

    /**
     * $tokenOpen and $tokenClose are no longer used (the stream restorer does
     * not depend on token delimiters) and are kept so 1.x calls still work.
     *
     * $recognizers run on every text; $languageRecognizers, by language
     * code, only on texts in that language (see languagesFor()).
     *
     * @param array<int, \EduLazaro\Laranon\Contracts\Recognizer> $recognizers
     * @param array<string, Strategy> $strategies
     * @param array<string, array<int, \EduLazaro\Laranon\Contracts\Recognizer>> $languageRecognizers
     */
    public function __construct(
        protected array $recognizers,
        protected array $strategies,
        protected string $defaultStrategy,
        protected VaultStore $vault,
        protected ?int $ttl = null,
        protected string $tokenOpen = '«',
        protected string $tokenClose = '»',
        protected ?LockProvider $locks = null,
        protected int $lockWait = 10,
        protected array $languageRecognizers = [],
    ) {
    }

    /**
     * Bind this run to a scope (conversation id, case id...). The token map
     * persists in the vault under this key, so «PER_1» stays the same person
     * across calls.
     */
    public function scope(?string $scope): static
    {
        $clone = clone $this;
        $clone->scope = $scope;

        return $clone;
    }

    /**
     * Use a different replacement strategy: a configured name (token, faker,
     * redact) or a Strategy instance.
     */
    public function strategy(Strategy|string $strategy): static
    {
        $clone = clone $this;
        $clone->currentStrategy = $strategy;

        return $clone;
    }

    /**
     * Restrict detection to the given span types.
     */
    public function only(array|string $types): static
    {
        $clone = clone $this;
        $clone->only = (array) $types;

        return $clone;
    }

    /**
     * Read the text as written in these languages (es, en, fr, ca) instead of
     * detecting it: their names and their numbers without a checksum.
     */
    public function language(array|string $languages): static
    {
        $clone = clone $this;
        $clone->languages = (array) $languages;

        return $clone;
    }

    /**
     * Skip the given span types.
     */
    public function except(array|string $types): static
    {
        $clone = clone $this;
        $clone->except = (array) $types;

        return $clone;
    }

    /**
     * Add known entities (values you already have: client names, emails...)
     * for exact matching. Accepts plain strings or arrays with value/type.
     *
     * They are found in any case ("Acme Health", "ACME HEALTH"), and by
     * default those written forms share one token and restore by context,
     * as names do, whatever type you give them. An entity with 'variants' =>
     * false, for a value where case means something (a code), gets one
     * token per written form instead.
     *
     * @param array<int, string|array{value: string, type?: string, variants?: bool}> $entities
     */
    public function withEntities(array $entities, string $defaultType = 'entity'): static
    {
        $normalized = [];

        foreach ($entities as $entity) {
            if (is_string($entity)) {
                $entity = ['value' => $entity, 'type' => $defaultType];
            }

            $entity['type'] ??= $defaultType;

            $normalized[] = $entity;
        }

        $clone = clone $this;
        $clone->extraRecognizers[] = new KnownEntitiesRecognizer($normalized);

        foreach ($normalized as $entity) {
            if (($entity['variants'] ?? true) === false) {
                $clone->literalEntities[] = ['type' => $entity['type'], 'value' => (string) $entity['value']];
            } elseif (! in_array($entity['type'], $clone->variantTypes, true)) {
                $clone->variantTypes[] = $entity['type'];
            }
        }

        return $clone;
    }

    /**
     * Collect known entities from models using the Anonymizable trait.
     *
     * @param iterable<int, object> $models
     */
    public function withModels(iterable $models): static
    {
        $entities = [];

        foreach ($models as $model) {
            if (method_exists($model, 'anonymizableEntities')) {
                $entities = array_merge($entities, $model->anonymizableEntities());
            }
        }

        return $entities === [] ? $this : $this->withEntities($entities);
    }

    /**
     * Like withEntities(), but the replacement is pinned to a stable id you
     * already own (a client's database id) instead of the ad-hoc
     * first-seen counter. The same id yields the same placeholder in every
     * scope and every call, so the token can live in your own database next
     * to the record instead of being assigned on first mention. If that
     * placeholder already belongs to another value in the map (the counter
     * gave «ENT_1» to another value in an earlier call of the same scope, or
     * the id comes back for a renamed record), the value gets a regular one
     * instead: restoring the right text wins over keeping the pin. Detection
     * still requires the value to be found in the text, exactly like
     * withEntities(), and 'variants' works the same.
     *
     * @param array<int, array{value: string, id: int, type?: string, variants?: bool}> $entities
     */
    public function withStableEntities(array $entities, string $defaultType = 'entity'): static
    {
        $normalized = [];

        foreach ($entities as $entity) {
            $entity['type'] ??= $defaultType;
            $normalized[] = $entity;
        }

        $clone = $this->withEntities($normalized, $defaultType);
        $clone->presets = array_merge($clone->presets, $normalized);

        return $clone;
    }

    /**
     * Override the vault TTL for this run.
     */
    public function ttl(?int $seconds): static
    {
        $clone = clone $this;
        $clone->ttl = $seconds;

        return $clone;
    }

    /**
     * Detect and replace PII. Returns the scrubbed text plus the token map.
     *
     * Under a scope, loading the map, extending it and writing it back runs
     * under a lock: two requests on the same scope would otherwise both hand
     * out «DNI_1» to different values, and the last write would make the
     * first one restore to the other person's DNI. Throws
     * Illuminate\Contracts\Cache\LockTimeoutException if the scope stays
     * busy for longer than vault.lock_wait seconds.
     */
    public function anonymize(string $text): AnonymizedText
    {
        return $this->underScopeLock(function () use ($text): AnonymizedText {
            $map = $this->loadMap();

            $this->applyPresets($map);

            $text = $this->anonymizeString($text, $map);

            $this->persistMap($map);

            return new AnonymizedText($text, $map);
        });
    }

    protected function underScopeLock(callable $callback): mixed
    {
        if ($this->scope === null || $this->locks === null) {
            return $callback();
        }

        return $this->locks
            ->lock('laranon-lock:' . $this->scope, self::LOCK_SECONDS)
            ->block($this->lockWait, $callback);
    }

    /**
     * Tell the map how this run's known entities group their written forms.
     */
    protected function prepareGrouping(TokenMap $map): void
    {
        $map->groupLikeNames($this->variantTypes);
        $map->keepLiteral($this->literalEntities);
    }

    protected function applyPresets(TokenMap $map): void
    {
        $this->prepareGrouping($map);

        foreach ($this->presets as $preset) {
            $map->preset($preset['type'], $preset['value'], $this->resolveStrategy(), $preset['id']);
        }
    }

    /**
     * Anonymize a string, accumulating into the given TokenMap (mutated in
     * place). No vault, no scope: the caller owns the map. This is the engine
     * a stateful AnonymizerSession runs on, so several strings of one prompt
     * share one in-memory map and stay consistent.
     */
    public function anonymizeString(string $text, TokenMap $map, ?array $languages = null): string
    {
        $this->prepareGrouping($map);

        $strategy = $this->resolveStrategy();
        $spans = $this->scan($text, $map, $languages);

        // Assign tokens in reading order (so «PER_1» is the first name seen),
        // then splice right-to-left so earlier offsets stay valid.
        $replacements = [];

        foreach ($spans as $i => $span) {
            $replacements[$i] = $map->replacementFor($span, $strategy, $text);
        }

        foreach (array_reverse($spans, true) as $i => $span) {
            $text = substr_replace($text, $replacements[$i], $span->start, $span->length);
        }

        return $text;
    }

    /**
     * Start a stateful, throwaway session that holds its own in-memory map.
     * The natural fit for a chat turn: anonymize the whole prompt, run tools,
     * restore the response, then let it die with the request. Nothing is
     * persisted anywhere. See AnonymizerSession.
     */
    public function newSession(): AnonymizerSession
    {
        $map = new TokenMap();

        $this->applyPresets($map);

        return new AnonymizerSession($this, $map);
    }

    /**
     * Convenience factory: a session from the container-configured anonymizer.
     * `$anon = Anonymizer::create();`
     */
    public static function create(): AnonymizerSession
    {
        return app('laranon')->newSession();
    }

    /**
     * Detect only: the resolved spans that anonymize() would replace. Pass
     * the scope's TokenMap so the known-word sweep also covers name words
     * established in earlier calls of this scope; omit it to sweep within
     * this text only.
     *
     * The recognizers of $languages run, or of the text's languages.
     *
     * @param array<int, string>|null $languages
     * @return array<int, \EduLazaro\Laranon\Support\Span>
     */
    public function scan(string $text, ?TokenMap $map = null, ?array $languages = null): array
    {
        $this->assertOnlyKnownTypes();

        $map ??= $this->loadMap();
        $spans = [];
        $recognizers = array_merge($this->recognizers, $this->extraRecognizers);

        foreach ($languages ?? $this->languagesFor($text, $map) as $language) {
            array_push($recognizers, ...($this->languageRecognizers[$language] ?? []));
        }

        foreach ($recognizers as $recognizer) {
            $spans = array_merge($spans, $recognizer->detect($text));
        }

        $spans = array_values(array_filter($spans, fn (Span $s) => $this->typeAllowed($s->type)));
        $spans = $this->neutralWhereGendersDisagree($spans);

        $spans = SpanResolver::resolve($spans);
        $spans = $this->sweepKnownNameWords($text, $spans, $map);
        $spans = $this->sweepIssuedReplacements($text, $spans, $map);

        usort($spans, fn (Span $a, Span $b) => $a->start <=> $b->start);

        return $spans;
    }

    /**
     * The same name read by the recognizers of several languages can get a
     * different gender from each: then it gets none, rather than a guess. A
     * recognizer with no gender for it has no say (the packs without gender
     * data of their own).
     *
     * @param array<int, Span> $spans
     * @return array<int, Span>
     */
    protected function neutralWhereGendersDisagree(array $spans): array
    {
        $genders = [];

        foreach ($spans as $span) {
            if ($span->gender !== null) {
                $genders[$span->start . '|' . $span->length . '|' . $span->type][$span->gender] = true;
            }
        }

        return array_map(
            fn (Span $span) => count($genders[$span->start . '|' . $span->length . '|' . $span->type] ?? []) > 1
                ? new Span($span->start, $span->length, $span->type, $span->value, $span->confidence)
                : $span,
            $spans,
        );
    }

    /**
     * The languages a text is read in, for the recognizers that depend on it
     * (names, phones with no country prefix...). The ones set with
     * language(), if any. Otherwise every language found in its paragraphs,
     * so a Spanish document with a passage pasted in English is read in
     * both (see paragraphLanguages()). With nothing to go by (digits alone,
     * a bare name), the language last found alone in this map (a
     * conversation, a case), if any. And all of them when none of that
     * gives one, or the whole text is clearly in a language with no pack, so
     * a name is never lost to a wrong guess.
     *
     * @return array<int, string>
     */
    public function languagesFor(string $text, TokenMap $map): array
    {
        $available = array_keys($this->languageRecognizers);

        if ($available === []) {
            return [];
        }

        if ($this->languages !== null) {
            $chosen = array_values(array_intersect($this->languages, $available));

            return $chosen !== [] ? $chosen : $available;
        }

        $this->detector ??= LanguageDetector::create();
        $scores = $this->detector->hasEnoughText($text) ? $this->detector->scores($text) : [];
        $clear = $this->clearLanguage($scores);

        if ($clear !== null && ! in_array($clear, $available, true)) {
            return $available;
        }

        // The document's language among the packs, from the same scores.
        $document = $this->clearLanguage(array_filter(
            $scores,
            fn (string $code) => in_array(strtolower(explode('-', $code)[0]), $available, true),
            ARRAY_FILTER_USE_KEY,
        ));

        $found = $document !== null ? [$document] : [];

        foreach (preg_split('/\R\h*\R/u', $text) as $paragraph) {
            if ($document !== null) {
                // A paragraph is read in the document's language when its words
                // agree with it, and when it is too short for n-grams, since a
                // few words mislead between close languages ("va", "mes" and
                // "dos" are Spanish too). A long one whose words point to
                // another language adds it when its n-grams agree.
                $words = $this->languagesByWords($paragraph, $available);

                if ($words === [] || in_array($document, $words, true) || ! $this->packDetector->hasEnoughText($paragraph)) {
                    continue;
                }

                $languages = $this->paragraphLanguages($paragraph, $available);

                if (count($languages) === 1) {
                    $found[] = $languages[0];
                }

                continue;
            }

            array_push($found, ...$this->paragraphLanguages($paragraph, $available));
        }

        $found = array_values(array_unique($found));

        if ($found !== []) {
            if (count($found) === 1) {
                $map->rememberLanguage($found[0]);
            }

            return $found;
        }

        return in_array($map->language(), $available, true) ? [$map->language()] : $available;
    }

    /**
     * The language whose score is clearly ahead of the rest, as a pack code
     * ("pt-BR" and "pt-PT" are both "pt"), or null.
     *
     * @param array<string, float> $scores
     */
    protected function clearLanguage(array $scores): ?string
    {
        if ($scores === []) {
            return null;
        }

        $best = reset($scores);
        $tied = array_unique(array_map(
            fn (string $code) => strtolower(explode('-', $code)[0]),
            array_keys(array_filter($scores, fn (float $score) => $best - $score <= LanguageDetector::THRESHOLD)),
        ));

        return count($tied) === 1 ? reset($tied) : null;
    }

    /**
     * The pack languages with the most grammatical words in the paragraph.
     *
     * @param array<int, string> $available
     * @return array<int, string>
     */
    protected function languagesByWords(string $paragraph, array $available): array
    {
        $words = ($this->packDetector ??= LanguageDetector::create($available))->wordCounts($paragraph);

        return $words === [] ? [] : array_keys(array_filter($words, fn (int $count) => $count === reset($words)));
    }

    /**
     * The languages of one paragraph, among the $available ones. A paragraph
     * too short for its n-grams (see LanguageDetector::hasEnoughText()) is
     * told by its grammatical words alone ("Llamar al ..." is Spanish or
     * Catalan), and by nothing when it has none. A longer one, by the clear
     * winner of its n-grams, and also the language with most grammatical
     * words if it is another, since reading it in both loses nothing; when
     * its n-grams tie, by the language with most grammatical words, or the
     * tied ones if it has none.
     *
     * @param array<int, string> $available
     * @return array<int, string>
     */
    protected function paragraphLanguages(string $paragraph, array $available): array
    {
        $detector = $this->packDetector ??= LanguageDetector::create($available);
        $most = $this->languagesByWords($paragraph, $available);

        if (! $detector->hasEnoughText($paragraph)) {
            return $most;
        }

        $scores = $detector->scores($paragraph);

        $best = reset($scores);
        $tied = array_values(array_unique(array_map(
            fn (string $code) => explode('-', $code)[0],
            array_keys(array_filter($scores, fn (float $score) => $best - $score <= LanguageDetector::THRESHOLD)),
        )));

        if (count($tied) === 1) {
            return array_values(array_unique([...$tied, ...$most]));
        }

        return $most !== [] ? $most : $tied;
    }

    /**
     * The name pattern needs 2+ capitalized words, so a person mentioned
     * later by a single word ("María" after "María López", "García" after
     * "el Sr. García") would leak in cleartext. Every name word already
     * tokenized (in this text or in an earlier call of this scope) is swept
     * as an exact, case-sensitive, word-bounded literal, and each bare
     * occurrence gets that word's own token. No identity is guessed: the
     * token belongs to the WORD, not to a person, so it is correct by
     * construction no matter how many people share it. The other ways a
     * document writes the word are swept too (see writtenForms()), and they
     * share its token.
     *
     * @param array<int, Span> $spans
     * @return array<int, Span>
     */
    protected function sweepKnownNameWords(string $text, array $spans, TokenMap $map): array
    {
        $known = [];
        $genders = [];

        foreach ($spans as $span) {
            if ($span->type === 'person' || $span->type === 'surname') {
                $known[$span->value] ??= $span->type;
                $genders[$span->value] ??= $span->gender;
            }
        }

        foreach (['person', 'surname'] as $type) {
            if (! $this->typeAllowed($type)) {
                continue;
            }

            foreach ($map->valuesForType($type) as $value) {
                $known[$value] ??= $type;
            }
        }

        $swept = [];

        foreach ($known as $literal => $type) {
            foreach ($this->writtenForms((string) $literal) as $form) {
                $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($form, '/') . '(?![\p{L}\p{N}])/u';

                if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($matches[0] as [$found, $offset]) {
                    // A bare "María" before "María López" takes the token first,
                    // so it carries the word's gender too.
                    $bare = new Span($offset, strlen($found), $type, $found, 0.85, $genders[$literal] ?? null);

                    if ($this->overlapsAny($bare, $spans) || $this->overlapsAny($bare, $swept)) {
                        continue;
                    }

                    $swept[] = $bare;
                }
            }
        }

        return array_merge($spans, $swept);
    }

    /**
     * The ways a document writes a name word: as seen, with an initial
     * capital, and in capitals with and without accents. Never in lowercase:
     * a surname like "Rey" would otherwise take "el rey" anywhere.
     *
     * @return array<int, string>
     */
    protected function writtenForms(string $word): array
    {
        $capitals = mb_strtoupper($word);

        return array_values(array_unique([$word, mb_convert_case($word, MB_CASE_TITLE), $capitals, ValueKey::withoutAccents($capitals)]));
    }

    /**
     * A replacement handed out earlier in this map ("Navarro" for López) can
     * also show up in a new text as a real word the detectors missed. Left
     * alone, restore() would turn that real Navarro into López. Each such
     * occurrence is replaced too, under the type of the replacement it
     * matches, so it gets its own surrogate and restores to itself. This runs
     * whatever only()/except() say: skipping it would make the restore wrong.
     *
     * Only word-shaped surrogates are swept. A delimited token («PER_1») in
     * the text is one of ours, so it stays as it is and anonymizing an
     * already anonymized text changes nothing.
     *
     * @param array<int, Span> $spans
     * @return array<int, Span>
     */
    protected function sweepIssuedReplacements(string $text, array $spans, TokenMap $map): array
    {
        $swept = [];

        foreach ($map->replacementTypes() as $replacement => $type) {
            $replacement = (string) $replacement;

            if (! TokenMap::startsWithWordChar($replacement) || ! TokenMap::endsWithWordChar($replacement)) {
                continue;
            }

            if (! TokenMap::occursIn($replacement, $text)) {
                continue;
            }

            $offset = 0;

            while (($found = strpos($text, $replacement, $offset)) !== false) {
                $offset = $found + strlen($replacement);
                $span = new Span($found, strlen($replacement), $type, $replacement);

                if (! $this->isWholeWord($text, $span) || $this->overlapsAny($span, $spans) || $this->overlapsAny($span, $swept)) {
                    continue;
                }

                $swept[] = $span;
            }
        }

        return array_merge($spans, $swept);
    }

    protected function isWholeWord(string $text, Span $span): bool
    {
        if (TokenMap::startsWithWordChar($span->value) && TokenMap::endsWithWordChar(substr($text, 0, $span->start))) {
            return false;
        }

        return ! (TokenMap::endsWithWordChar($span->value) && TokenMap::startsWithWordChar(substr($text, $span->end())));
    }

    /**
     * only() with a type no recognizer produces ("dnii" for "dni") would
     * detect nothing, and the result would look exactly like a text with no
     * PII. Checked here, when detecting, so a type that withEntities() adds
     * later in the chain is known. except() is not checked: a typo there only
     * anonymizes more.
     */
    protected function assertOnlyKnownTypes(): void
    {
        if ($this->only === null) {
            return;
        }

        $known = [];

        foreach (array_merge($this->recognizers, $this->extraRecognizers, ...array_values($this->languageRecognizers)) as $recognizer) {
            foreach (method_exists($recognizer, 'types') ? $recognizer->types() : [$recognizer->type()] as $type) {
                $known[$type] = true;
            }
        }

        $unknown = array_values(array_filter($this->only, fn (string $type) => ! isset($known[$type])));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown laranon type [' . implode(', ', $unknown) . '] in only(). Known types: ' . implode(', ', array_keys($known)) . '.'
            );
        }
    }

    protected function typeAllowed(string $type): bool
    {
        if ($this->only !== null && ! in_array($type, $this->only, true)) {
            return false;
        }

        if ($this->except !== null && in_array($type, $this->except, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, Span> $spans
     */
    protected function overlapsAny(Span $span, array $spans): bool
    {
        foreach ($spans as $other) {
            if ($span->overlaps($other)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace tokens back with their original values. Accepts a TokenMap,
     * an AnonymizedText, a raw entries array, or null to load from the scope.
     */
    public function restore(string $text, TokenMap|AnonymizedText|array|null $map = null): string
    {
        return match (true) {
            $map instanceof TokenMap => $map->restore($text),
            $map instanceof AnonymizedText => $map->restore($text),
            is_array($map) => (new TokenMap($map))->restore($text),
            default => $this->loadMap()->restore($text),
        };
    }

    /**
     * Streaming-safe restorer: push chunks as they arrive (SSE) and
     * tokens split across chunk boundaries are still restored.
     */
    public function stream(TokenMap|AnonymizedText|null $map = null): StreamDeanonymizer
    {
        $map = match (true) {
            $map instanceof AnonymizedText => $map->map,
            $map instanceof TokenMap => $map,
            default => $this->loadMap(),
        };

        return new StreamDeanonymizer($map);
    }

    /**
     * Drop the persisted map for the current scope. After this, tokens can no
     * longer be reversed: pseudonymization becomes effective anonymization.
     */
    public function forget(): void
    {
        if ($this->scope !== null) {
            $this->vault->forget($this->scope);
        }
    }

    protected function loadMap(): TokenMap
    {
        if ($this->scope === null) {
            return new TokenMap();
        }

        return TokenMap::fromArray($this->vault->get($this->scope));
    }

    protected function persistMap(TokenMap $map): void
    {
        if ($this->scope !== null && ! $map->isEmpty()) {
            $this->vault->put($this->scope, $map->toArray(), $this->ttl);
        }
    }

    protected function resolveStrategy(): Strategy
    {
        $strategy = $this->currentStrategy ?? $this->defaultStrategy;

        if ($strategy instanceof Strategy) {
            return $strategy;
        }

        if (! isset($this->strategies[$strategy])) {
            throw new InvalidArgumentException("Unknown laranon strategy [{$strategy}].");
        }

        return $this->strategies[$strategy];
    }
}
