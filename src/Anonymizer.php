<?php

namespace EduLazaro\Laranon;

use EduLazaro\Laranon\Contracts\Strategy;
use EduLazaro\Laranon\Contracts\VaultStore;
use EduLazaro\Laranon\Recognizers\Universal\KnownEntitiesRecognizer;
use EduLazaro\Laranon\Streaming\StreamDeanonymizer;
use EduLazaro\Laranon\Support\AnonymizedText;
use EduLazaro\Laranon\Support\Span;
use EduLazaro\Laranon\Support\SpanResolver;
use EduLazaro\Laranon\Support\TokenMap;
use InvalidArgumentException;

/**
 * The orchestrator: runs every recognizer over the text, resolves overlaps,
 * replaces matches according to the strategy and keeps the token map so the
 * process can be reversed. Fluent methods return clones, so the container
 * singleton is never mutated.
 */
class Anonymizer
{
    protected ?string $scope = null;

    /** @var array<int, string>|null */
    protected ?array $only = null;

    /** @var array<int, string>|null */
    protected ?array $except = null;

    /** @var array<int, \EduLazaro\Laranon\Contracts\Recognizer> */
    protected array $extraRecognizers = [];

    /** @var array<int, array{value: string, type: string, id: int}> */
    protected array $presets = [];

    protected Strategy|string|null $currentStrategy = null;

    /**
     * @param array<int, \EduLazaro\Laranon\Contracts\Recognizer> $recognizers
     * @param array<string, Strategy> $strategies
     */
    public function __construct(
        protected array $recognizers,
        protected array $strategies,
        protected string $defaultStrategy,
        protected VaultStore $vault,
        protected ?int $ttl = null,
        protected string $tokenOpen = '«',
        protected string $tokenClose = '»',
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
     * @param array<int, string|array{value: string, type?: string}> $entities
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
     * first-seen counter. The same id always yields the same placeholder,
     * in every scope and every call, forever, so the token can live in your
     * own database next to the record instead of being assigned on first
     * mention. Detection still requires the value to be found in the text,
     * exactly like withEntities().
     *
     * @param array<int, array{value: string, id: int, type?: string}> $entities
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
     */
    public function anonymize(string $text): AnonymizedText
    {
        $map = $this->loadMap();

        foreach ($this->presets as $preset) {
            $map->preset($preset['type'], $preset['value'], $this->resolveStrategy(), $preset['id']);
        }

        $text = $this->anonymizeString($text, $map);

        $this->persistMap($map);

        return new AnonymizedText($text, $map);
    }

    /**
     * Anonymize a string, accumulating into the given TokenMap (mutated in
     * place). No vault, no scope: the caller owns the map. This is the engine
     * a stateful AnonymizerSession runs on, so several strings of one prompt
     * share one in-memory map and stay consistent.
     */
    public function anonymizeString(string $text, TokenMap $map): string
    {
        $strategy = $this->resolveStrategy();
        $spans = $this->scan($text, $map);

        // Assign tokens in reading order (so «PER_1» is the first name seen),
        // then splice right-to-left so earlier offsets stay valid.
        $replacements = [];

        foreach ($spans as $i => $span) {
            $replacements[$i] = $map->replacementFor($span, $strategy);
        }

        foreach (array_reverse($spans, true) as $i => $span) {
            $text = substr_replace($text, $replacements[$i], $span->start, $span->length);
        }

        return $text;
    }

    /**
     * Start a stateful, throwaway session that holds its own in-memory map.
     * The natural fit for a chat turn: anonymize the whole prompt, run tools,
     * deanonymize the response, then let it die with the request. Nothing is
     * persisted anywhere. See AnonymizerSession.
     */
    public function newSession(): AnonymizerSession
    {
        return new AnonymizerSession($this, new TokenMap(), $this->tokenOpen, $this->tokenClose);
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
     * @return array<int, \EduLazaro\Laranon\Support\Span>
     */
    public function scan(string $text, ?TokenMap $map = null): array
    {
        $map ??= $this->loadMap();
        $spans = [];

        foreach (array_merge($this->recognizers, $this->extraRecognizers) as $recognizer) {
            $spans = array_merge($spans, $recognizer->detect($text));
        }

        $spans = array_values(array_filter($spans, fn (Span $s) => $this->typeAllowed($s->type)));

        $spans = SpanResolver::resolve($spans);
        $spans = $this->sweepKnownNameWords($text, $spans, $map);

        usort($spans, fn (Span $a, Span $b) => $a->start <=> $b->start);

        return $spans;
    }

    /**
     * The name pattern needs 2+ capitalized words, so a person mentioned
     * later by a single word ("María" after "María López", "García" after
     * "el Sr. García") would leak in cleartext. Every name word already
     * tokenized (in this text or in an earlier call of this scope) is swept
     * as an exact, case-sensitive, word-bounded literal, and each bare
     * occurrence gets that word's own token. No identity is guessed: the
     * token belongs to the WORD, not to a person, so it is correct by
     * construction no matter how many people share it.
     *
     * @param array<int, Span> $spans
     * @return array<int, Span>
     */
    protected function sweepKnownNameWords(string $text, array $spans, TokenMap $map): array
    {
        $known = [];

        foreach ($spans as $span) {
            if ($span->type === 'person' || $span->type === 'surname') {
                $known[$span->value] ??= $span->type;
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
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote((string) $literal, '/') . '(?![\p{L}\p{N}])/u';

            if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$found, $offset]) {
                $bare = new Span($offset, strlen($found), $type, $found, 0.85);

                if ($this->overlapsAny($bare, $spans) || $this->overlapsAny($bare, $swept)) {
                    continue;
                }

                $swept[] = $bare;
            }
        }

        return array_merge($spans, $swept);
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
    public function deanonymize(string $text, TokenMap|AnonymizedText|array|null $map = null): string
    {
        return match (true) {
            $map instanceof TokenMap => $map->restore($text),
            $map instanceof AnonymizedText => $map->restore($text),
            is_array($map) => $map === [] ? $text : strtr($text, $map),
            default => $this->loadMap()->restore($text),
        };
    }

    /**
     * Streaming-safe deanonymizer: push chunks as they arrive (SSE) and
     * tokens split across chunk boundaries are still restored.
     */
    public function stream(TokenMap|AnonymizedText|null $map = null): StreamDeanonymizer
    {
        $map = match (true) {
            $map instanceof AnonymizedText => $map->map,
            $map instanceof TokenMap => $map,
            default => $this->loadMap(),
        };

        return new StreamDeanonymizer($map, $this->tokenOpen, $this->tokenClose);
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
