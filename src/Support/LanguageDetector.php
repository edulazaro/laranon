<?php

namespace EduLazaro\Laranon\Support;

/**
 * Detects the language of a text by its n-grams (Cavnar and Trenkle, 1994):
 * the sequences of one to three letters of the text, ranked by frequency,
 * are compared with each language's ranking, and the closest one wins.
 *
 * A port of patrickschur/language-detection (MIT License, Copyright (c)
 * Patrick Schur): the same algorithm and the same profiles of its 116
 * languages (data/languages), so it gives the same scores without the
 * dependency. https://github.com/patrickschur/language-detection
 */
final class LanguageDetector
{
    /**
     * How many n-grams a ranking keeps, the text's and each profile's.
     */
    private const MAX_NGRAMS = 310;

    /**
     * Two scores closer than this are a tie: the package's own threshold for
     * its best results.
     */
    public const THRESHOLD = 0.025;

    /** @var array<string, array<string, int>> language code => n-gram => rank */
    private array $profiles = [];

    /**
     * @param array<int, string> $languages the codes to choose from (es, en,
     *                                      pt-BR, zh-Hans...), or every one
     */
    public function __construct(array $languages = [], ?string $dataPath = null)
    {
        $directory = ($dataPath ?? dirname(__DIR__, 2) . '/data') . '/languages';

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $code = basename($file, '.php');

            if ($languages === [] || in_array($code, $languages, true)) {
                $this->profiles[$code] = array_flip(require $file);
            }
        }
    }

    /**
     * A detector that chooses among $languages, or among every language.
     *
     * @param array<int, string> $languages
     */
    public static function create(array $languages = [], ?string $dataPath = null): self
    {
        return new self($languages, $dataPath);
    }

    /**
     * The language of the text, or null when it cannot tell: no letters, or
     * a runner-up within THRESHOLD of the best score.
     */
    public function detect(string $text): ?string
    {
        $scores = $this->scores($text);

        if ($scores === []) {
            return null;
        }

        [$best, $second] = array_pad(array_values($scores), 2, null);

        return $second !== null && $best - $second <= self::THRESHOLD ? null : array_key_first($scores);
    }

    /**
     * A score from 0 to 1 for every language, best first.
     *
     * @return array<string, float>
     */
    public function scores(string $text): array
    {
        $ngrams = $this->ngrams(mb_strtolower($text));

        if ($ngrams === []) {
            return [];
        }

        $scores = [];

        foreach ($this->profiles as $code => $profile) {
            $distance = 0;

            foreach ($ngrams as $rank => $ngram) {
                $distance += isset($profile[$ngram]) ? abs($rank - $profile[$ngram]) : self::MAX_NGRAMS;
            }

            $scores[$code] = 1 - ($distance / (self::MAX_NGRAMS * count($ngrams)));
        }

        arsort($scores, SORT_NUMERIC);

        return $scores;
    }

    /**
     * The text's n-grams, most frequent first: every sequence of one to three
     * letters of each word, with "_" marking its edges, counted, turned into
     * a share of the n-grams of its length, and ranked.
     *
     * @return array<int, string>
     */
    private function ngrams(string $text): array
    {
        $counts = [];

        foreach (preg_split('/[^\pL]+(?<![\x27\x60\x{2019}])/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $word = "_{$word}_";
            $length = mb_strlen($word);

            for ($size = 1; $size <= 3; $size++) {
                for ($start = 0; $start + $size - 1 < $length; $start++) {
                    $ngram = mb_substr($word, $start, $size);
                    $counts[$size][$ngram] = ($counts[$size][$ngram] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            return [];
        }

        foreach ($counts as $size => $ngrams) {
            $total = array_sum($ngrams);

            foreach ($ngrams as $ngram => $count) {
                $counts[$size][$ngram] = $count / $total;
            }
        }

        $ranked = array_merge(...$counts);
        unset($ranked['_']);

        arsort($ranked, SORT_NUMERIC);

        return array_slice(array_keys($ranked), 0, self::MAX_NGRAMS);
    }
}
