<?php

namespace EduLazaro\Laranon\Support;

use EduLazaro\Laranon\Contracts\Strategy;

/**
 * The match store: replacement ↔ original value pairs plus per-type counters.
 * Stable within a scope: the same value always resolves to the same token.
 * Values are grouped by ValueKey, so the ways of writing one name ("García",
 * "GARCÍA") share a token, and every written form is remembered.
 */
final class TokenMap
{
    /**
     * Bytes looked at on each side of a name token, when restoring, to tell a
     * heading in capitals from running text.
     */
    public const CONTEXT_BYTES = 80;

    /**
     * Enough retries for a generative strategy to walk its whole name pool
     * (see FakerStrategy) before the suffix fallback kicks in.
     */
    private const MAX_RETRIES = 100;

    private const WORD_CHAR = '[\p{L}\p{N}]';

    /** @var array<string, string> "type|key" => replacement */
    protected array $index = [];

    /** @var array<string, array<int, string>> "type|key" => written forms, first seen first */
    protected array $forms = [];

    /** @var array<string, true> own types grouped like names (see groupLikeNames()) */
    protected array $nameTypes = [];

    /** @var array<string, true> "type|key" of known entities kept literal (see keepLiteral()) */
    protected array $literal = [];

    /** @var array<string, array<int, array{0: string, 1: bool}>>|null */
    private ?array $heads = null;

    private int $headsBuiltFor = -1;

    /** @var array<string, array<int, string>>|null */
    private ?array $contextual = null;

    /**
     * Index keys are rebuilt from the values, so a map written by an older
     * version (keyed by the literal value) groups the same way as a new one.
     *
     * @param array<string, string> $entries replacement => original, as first seen (reversible only)
     * @param array<string, string> $index "type|key" => replacement
     * @param array<string, int> $counters type => last index used
     * @param array<string, array<int, string>> $forms "type|key" => written forms
     * @param array<int, string> $nameTypes own types grouped like names
     * @param array<int, string> $literal "type|key" of known entities kept literal
     */
    public function __construct(
        protected array $entries = [],
        array $index = [],
        protected array $counters = [],
        array $forms = [],
        array $nameTypes = [],
        array $literal = [],
    ) {
        $this->nameTypes = array_fill_keys($nameTypes, true);
        $this->literal = array_fill_keys($literal, true);

        $this->regroup($index, $forms);
    }

    /**
     * Keep these known entities literal: each of their written forms gets
     * its own token ("AB-12" and "ab-12" are not merged), for values where
     * case means something, like codes. Values already in the map are
     * regrouped.
     *
     * @param array<int, array{type: string, value: string}> $entities
     */
    public function keepLiteral(array $entities): void
    {
        $new = [];

        foreach ($entities as $entity) {
            $key = $entity['type'] . '|' . ValueKey::name($entity['value']);

            if (! isset($this->literal[$key])) {
                $new[$key] = true;
            }
        }

        if ($new === []) {
            return;
        }

        $this->literal += $new;

        $this->regroup($this->index, $this->forms);
    }

    /**
     * Group the written forms of these types like names ("Acme Health" and
     * "ACME HEALTH" share a token and restore by context). For the types an
     * application gives its known entities (withEntities()). A type with a
     * rule of its own (a name, a DNI, a phone...) keeps that rule. Values
     * already in the map are regrouped.
     *
     * @param array<int, string> $types
     */
    public function groupLikeNames(array $types): void
    {
        $new = array_filter(
            array_diff($types, array_keys($this->nameTypes)),
            fn (string $type) => ! ValueKey::hasOwnRule($type),
        );

        if ($new === []) {
            return;
        }

        $this->nameTypes += array_fill_keys($new, true);

        $this->regroup($this->index, $this->forms);
    }

    /**
     * Rebuild the index keys from the values, merging the keys that now group
     * together. The first replacement of a group wins; the others stay in the
     * entries, so they still restore.
     *
     * @param array<string, string> $index
     * @param array<string, array<int, string>> $forms
     */
    private function regroup(array $index, array $forms): void
    {
        $this->index = [];
        $this->forms = [];
        $this->contextual = null;

        foreach ($index as $key => $replacement) {
            $key = (string) $key;
            $type = (string) strstr($key, '|', true);
            $values = $forms[$key] ?? [substr($key, strlen($type) + 1)];
            $grouped = $this->keyFor($type, (string) $values[0]);

            $this->index[$grouped] ??= $replacement;

            foreach ($values as $value) {
                $this->addForm($grouped, (string) $value);
            }
        }
    }

    protected function groupsLikeName(string $type): bool
    {
        return ValueKey::isName($type) || isset($this->nameTypes[$type]);
    }

    /**
     * Resolve the replacement for a span, reusing the existing token when the
     * same value was already seen under this map. For reversible strategies,
     * a new replacement is never shared with another value, never equal to
     * the value itself, and never a string already present in $context (the
     * text being anonymized): any of the three would restore to the wrong
     * text or leak the original.
     */
    public function replacementFor(Span $span, Strategy $strategy, string $context = ''): string
    {
        $key = $this->keyFor($span->type, $span->value);

        if (isset($this->index[$key])) {
            $this->addForm($key, $span->value);

            return $this->index[$key];
        }

        $next = ($this->counters[$span->type] ?? 0) + 1;
        $this->counters[$span->type] = $next;

        $replacement = $strategy->replacement($span, $next);

        if ($strategy->reversible()) {
            $attempt = $next;

            while ($this->unusable($replacement, $span->value, $context) && $attempt < $next + self::MAX_RETRIES) {
                $replacement = $strategy->replacement($span, ++$attempt);
            }

            $base = $replacement;
            $suffix = 1;

            while ($this->unusable($replacement, $span->value, $context)) {
                $replacement = $base . '_' . $suffix++;
            }
        }

        $this->index[$key] = $replacement;
        $this->addForm($key, $span->value);

        if ($strategy->reversible() && ! isset($this->entries[$replacement])) {
            $this->entries[$replacement] = $span->value;
        }

        return $replacement;
    }

    protected function keyFor(string $type, string $value): string
    {
        // "|=" so a literal key never equals a grouped one.
        if (isset($this->literal[$type . '|' . ValueKey::name($value)])) {
            return $type . '|=' . $value;
        }

        return $type . '|' . (isset($this->nameTypes[$type]) ? ValueKey::name($value) : ValueKey::of($type, $value));
    }

    protected function addForm(string $key, string $value): void
    {
        if (! in_array($value, $this->forms[$key] ?? [], true)) {
            $this->forms[$key][] = $value;
            $this->contextual = null;
        }
    }

    protected function unusable(string $replacement, string $value, string $context): bool
    {
        return $replacement === $value
            || $this->isTaken($replacement, $value)
            || ($context !== '' && self::occursIn($replacement, $context));
    }

    /**
     * Whether a replacement is already mapped to a DIFFERENT original value.
     * The same original under another type may share its replacement:
     * restoration is unaffected when the restored text is identical.
     */
    protected function isTaken(string $replacement, string $value): bool
    {
        return isset($this->entries[$replacement]) && $this->entries[$replacement] !== $value;
    }

    /**
     * Pin the replacement for a value to a stable id (e.g. a client's
     * database id) instead of the auto-incrementing counter, reusing the
     * same strategy formatting that organically-detected spans get. If that
     * replacement already belongs to another value in this map (the counter
     * gave «ENT_1» to another value in an earlier call of the same scope, or
     * the same id comes back for a renamed record), the value gets a regular
     * replacement instead: restoring the right text matters more than
     * keeping the pin. A no-op if the value already resolved to something in
     * this map.
     */
    public function preset(string $type, string $value, Strategy $strategy, int $id): void
    {
        $key = $this->keyFor($type, $value);

        if (isset($this->index[$key])) {
            return;
        }

        $span = new Span(0, strlen($value), $type, $value);

        $replacement = $strategy->replacement($span, $id);

        if ($strategy->reversible() && ($replacement === $value || $this->isTaken($replacement, $value))) {
            $this->replacementFor($span, $strategy);

            return;
        }

        $this->index[$key] = $replacement;
        $this->addForm($key, $value);

        if ($strategy->reversible() && ! isset($this->entries[$replacement])) {
            $this->entries[$replacement] = $value;
        }
    }

    /**
     * Distinct original values already resolved for a given type in this
     * map, regardless of which call established them. Lets later calls in
     * the same scope link a bare mention ("María") to a person only
     * introduced by full name in an earlier turn. Every written form counts
     * ("García" and "GARCÍA").
     *
     * @return array<int, string>
     */
    public function valuesForType(string $type): array
    {
        $prefix = $type . '|';
        $values = [];

        foreach ($this->forms as $key => $forms) {
            if (str_starts_with((string) $key, $prefix)) {
                array_push($values, ...$forms);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * The type each reversible replacement was issued for.
     *
     * @return array<string, string> replacement => type
     */
    public function replacementTypes(): array
    {
        $types = [];

        foreach ($this->index as $key => $replacement) {
            if (isset($this->entries[$replacement])) {
                $types[(string) $replacement] ??= strstr($key, '|', true);
            }
        }

        return $types;
    }

    /**
     * Replace tokens back with their original values, in a single pass and
     * leftmost-longest, like strtr(). A replacement that starts or ends with
     * a letter or digit only matches as a whole word: "Rey" restores in
     * "Javier Rey firma" but not in "Reyes Católicos".
     *
     * A name seen written in several ways ("García", "GARCÍA") restores to
     * the way that fits its surroundings: see restoredValue().
     *
     * $before and $after are the text right around $text when it is a
     * fragment of a longer one (a streamed chunk): they only decide word
     * boundaries and that context, and are not part of the result.
     */
    public function restore(string $text, string $before = '', string $after = ''): string
    {
        if ($this->entries === [] || $text === '') {
            return $text;
        }

        if (! preg_match_all('/' . self::WORD_CHAR . '+|[^\p{L}\p{N}]/u', $text, $tokens, PREG_OFFSET_CAPTURE)) {
            // Not valid UTF-8: no word boundaries to honour, swap bytes.
            return strtr($text, $this->entries);
        }

        $heads = $this->heads();
        $length = strlen($text);
        $restored = '';
        $cursor = 0;

        foreach ($tokens[0] as [$token, $offset]) {
            if ($offset < $cursor || ! isset($heads[$token])) {
                continue;
            }

            // A word that started in $before: "Rey" inside "MonteRey".
            if ($offset === 0 && self::endsWithWordChar($before) && self::startsWithWordChar($token)) {
                continue;
            }

            foreach ($heads[$token] as [$replacement, $wordTail]) {
                $size = strlen($replacement);

                if ($offset + $size > $length || substr_compare($text, $replacement, $offset, $size) !== 0) {
                    continue;
                }

                $next = $offset + $size;

                if ($wordTail && ($next < $length ? preg_match('/\G' . self::WORD_CHAR . '/u', $text, $m, 0, $next) === 1 : self::startsWithWordChar($after))) {
                    continue;
                }

                $restored .= substr($text, $cursor, $offset - $cursor)
                    . $this->restoredValue($replacement, $text, $offset, $next, $before, $after);
                $cursor = $next;

                break;
            }
        }

        return $restored . substr($text, $cursor);
    }

    /**
     * Replacements whose restored text depends on the words around them:
     * names seen written in more than one way.
     *
     * @return array<int, string>
     */
    public function contextualReplacements(): array
    {
        return array_map('strval', array_keys($this->contextual()));
    }

    /**
     * What a replacement restores to. A name seen written in several ways
     * restores, among words in capitals, to its capitals form ("DEMANDANTE:
     * JUAN GARCÍA") and otherwise to its other form ("el demandante Juan
     * García"); with no words around, to the first form seen. A name seen in
     * one form always restores to exactly that form: nothing is ever
     * restored to a spelling the text did not contain.
     */
    protected function restoredValue(string $replacement, string $text, int $offset, int $next, string $before, string $after): string
    {
        $forms = $this->contextual()[$replacement] ?? null;

        if ($forms === null) {
            return $this->entries[$replacement];
        }

        $start = max(0, $offset - self::CONTEXT_BYTES);
        $left = substr($before . substr($text, $start, $offset - $start), -self::CONTEXT_BYTES);
        $right = substr(substr($text, $next, self::CONTEXT_BYTES) . $after, 0, self::CONTEXT_BYTES);

        $words = $this->neighbourWords($left, $right);

        if ($words === []) {
            return $forms[0];
        }

        $capitals = array_filter($words, fn (string $word) => self::inCapitals($word)) === $words;

        foreach ($forms as $form) {
            if (self::inCapitals($form) === $capitals) {
                return $form;
            }
        }

        return $forms[0];
    }

    /**
     * The nearest word of 2+ letters on each side, on the same line (a
     * heading is a line), ignoring the replacements themselves («PER_1» is
     * not a word in capitals).
     *
     * @return array<int, string>
     */
    protected function neighbourWords(string $left, string $right): array
    {
        // Within the line. Otherwise a full window was cut by bytes, and the
        // word, token or character at its far edge may be partial ("ER_1»"
        // looks like a word in capitals).
        if (($newline = strrpos($left, "\n")) !== false) {
            $left = substr($left, $newline + 1);
        } elseif (strlen($left) >= self::CONTEXT_BYTES) {
            $left = (string) preg_replace('/^\S*/', '', $left);
        }

        if (($newline = strpos($right, "\n")) !== false) {
            $right = substr($right, 0, $newline);
        } elseif (strlen($right) >= self::CONTEXT_BYTES) {
            $right = (string) preg_replace('/\S*$/', '', $right);
        }

        $blank = array_fill_keys(array_map('strval', array_keys($this->entries)), ' ');
        $left = strtr($left, $blank);
        $right = strtr($right, $blank);

        $words = [];

        if (preg_match_all('/\p{L}{2,}/u', $left, $m) && $m[0] !== []) {
            $words[] = end($m[0]);
        }

        if (preg_match('/\p{L}{2,}/u', $right, $m)) {
            $words[] = $m[0];
        }

        return $words;
    }

    protected static function inCapitals(string $text): bool
    {
        return mb_strtoupper($text) === $text && mb_strtolower($text) !== $text;
    }

    /**
     * replacement => written forms, for name-type values seen in more than
     * one form.
     *
     * @return array<string, array<int, string>>
     */
    private function contextual(): array
    {
        if ($this->contextual !== null) {
            return $this->contextual;
        }

        $contextual = [];

        foreach ($this->index as $key => $replacement) {
            $forms = $this->forms[$key] ?? [];

            if (count($forms) > 1 && isset($this->entries[$replacement]) && $this->groupsLikeName((string) strstr((string) $key, '|', true))) {
                $contextual[(string) $replacement] = $forms;
            }
        }

        return $this->contextual = $contextual;
    }

    /**
     * Replacements grouped by their first word (or first character, when
     * they start with a delimiter like «), longest first. Restoring then
     * costs one lookup per word of the text, however big the map: a single
     * regex alternating every replacement stops compiling at ~2,000 entries.
     *
     * @return array<string, array<int, array{0: string, 1: bool}>>
     */
    private function heads(): array
    {
        if ($this->heads !== null && $this->headsBuiltFor === count($this->entries)) {
            return $this->heads;
        }

        $heads = [];

        foreach (array_keys($this->entries) as $replacement) {
            $replacement = (string) $replacement;

            if (preg_match('/^(?:' . self::WORD_CHAR . '+|.)/us', $replacement, $m)) {
                $heads[$m[0]][] = [$replacement, self::endsWithWordChar($replacement)];
            }
        }

        foreach ($heads as &$candidates) {
            usort($candidates, fn (array $a, array $b) => strlen($b[0]) <=> strlen($a[0]));
        }

        $this->headsBuiltFor = count($this->entries);

        return $this->heads = $heads;
    }

    /**
     * Whether $needle appears in $haystack, as a whole word at the edges
     * that are letters or digits (the same rule restore() applies).
     */
    public static function occursIn(string $needle, string $haystack): bool
    {
        if ($needle === '' || ! str_contains($haystack, $needle)) {
            return false;
        }

        $pattern = (self::startsWithWordChar($needle) ? '(?<!' . self::WORD_CHAR . ')' : '')
            . preg_quote($needle, '/')
            . (self::endsWithWordChar($needle) ? '(?!' . self::WORD_CHAR . ')' : '');

        // An unreadable haystack (invalid UTF-8) counts as a hit: rejecting a
        // candidate is always safe, accepting a clash is not.
        return preg_match('/' . $pattern . '/u', $haystack) !== 0;
    }

    public static function startsWithWordChar(string $text): bool
    {
        return preg_match('/^' . self::WORD_CHAR . '/u', $text) === 1;
    }

    public static function endsWithWordChar(string $text): bool
    {
        return preg_match('/' . self::WORD_CHAR . '$/u', $text) === 1;
    }

    /**
     * @return array<string, string> replacement => original
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [] && $this->index === [];
    }

    public function toArray(): array
    {
        return [
            'entries' => $this->entries,
            'index' => $this->index,
            'counters' => $this->counters,
            'forms' => $this->forms,
            'name_types' => array_keys($this->nameTypes),
            'literal' => array_keys($this->literal),
        ];
    }

    public static function fromArray(?array $payload): self
    {
        return new self(
            $payload['entries'] ?? [],
            $payload['index'] ?? [],
            $payload['counters'] ?? [],
            $payload['forms'] ?? [],
            $payload['name_types'] ?? [],
            $payload['literal'] ?? [],
        );
    }
}
