<?php

namespace App\Services\Ocr\Lexicon;

use App\Support\ArabicTextNormalizer;

/**
 * Indexed OCR trade lexicon: phrase-first lookup, morphology, fuzzy candidates.
 */
class OcrLexicon
{
    /** @var list<array{term:string, family:string, confirmed:bool, source:string, catalog_name:string, phrase_len:int}>|null */
    protected static ?array $entriesCache = null;

    public function __construct(
        protected ArabicMorphology $morphology,
        protected WeightedEditDistance $distance,
    ) {}

    /**
     * @return list<array{term:string, family:string, confirmed:bool, source:string, catalog_name:string, phrase_len:int}>
     */
    public function allEntries(): array
    {
        if (self::$entriesCache !== null) {
            return self::$entriesCache;
        }

        $out = [];
        foreach ((array) config('ocr_lexicon.families', []) as $family => $meta) {
            $catalogName = (string) ($meta['catalog_name'] ?? $family);
            foreach ((array) ($meta['synonyms'] ?? []) as $source => $terms) {
                foreach ((array) $terms as $row) {
                    $term = ArabicTextNormalizer::normalize((string) ($row['term'] ?? ''));
                    if ($term === '') {
                        continue;
                    }
                    $out[] = [
                        'term' => $term,
                        'family' => (string) $family,
                        'confirmed' => (bool) ($row['confirmed'] ?? false),
                        'source' => (string) $source,
                        'catalog_name' => $catalogName,
                        'phrase_len' => count(preg_split('/\s+/u', $term) ?: []),
                    ];
                }
            }
        }

        // Runtime learned aliases (approved → confirmed; pending → suggest only).
        try {
            $aliasService = app(\App\Services\Ocr\OcrTermAliasService::class);
            foreach (array_merge($aliasService->approvedEntries(), $aliasService->pendingEntries()) as $row) {
                $term = ArabicTextNormalizer::normalize((string) ($row['term'] ?? ''));
                $family = (string) ($row['family'] ?? '');
                if ($term === '' || $family === '') {
                    continue;
                }
                $out[] = [
                    'term' => $term,
                    'family' => $family,
                    'confirmed' => (bool) ($row['confirmed'] ?? false),
                    'source' => 'learned',
                    'catalog_name' => $this->catalogNameFor($family),
                    'phrase_len' => count(preg_split('/\s+/u', $term) ?: []),
                ];
            }
        } catch (\Throwable) {
            // Table may not exist yet during early migrate.
        }

        usort($out, function ($a, $b) {
            if ($a['phrase_len'] !== $b['phrase_len']) {
                return $b['phrase_len'] <=> $a['phrase_len'];
            }

            return mb_strlen($b['term']) <=> mb_strlen($a['term']);
        });

        return self::$entriesCache = $out;
    }

    public static function clearIndexCache(): void
    {
        self::$entriesCache = null;
    }

    /**
     * @return list<string>
     */
    public function readingHints(int $limit = 120): array
    {
        $words = [];
        foreach ($this->allEntries() as $e) {
            foreach (preg_split('/\s+/u', $e['term']) ?: [] as $w) {
                if ($w !== '') {
                    $words[$w] = true;
                }
            }
        }

        $list = array_keys($words);
        // Prefer shorter vocabulary tokens for the prompt budget.
        usort($list, fn ($a, $b) => mb_strlen($a) <=> mb_strlen($b));

        return array_slice($list, 0, max(1, $limit));
    }

    public function catalogFamilyFor(string $lexiconFamily, ?float $angle = null): string
    {
        if ($lexiconFamily === 'elbow') {
            if ($angle !== null && abs($angle - 45.0) < 0.01) {
                return 'elbow_45';
            }

            return 'elbow_90';
        }

        $map = (array) config('ocr_lexicon.catalog_family_map', []);

        return (string) ($map[$lexiconFamily] ?? $lexiconFamily);
    }

    public function catalogNameFor(string $lexiconFamily): string
    {
        $meta = (array) config("ocr_lexicon.families.{$lexiconFamily}", []);

        return (string) ($meta['catalog_name'] ?? $lexiconFamily);
    }

    /**
     * @return array{
     *   lexicon_family:?string,
     *   family:?string,
     *   family_candidates:list<array<string,mixed>>,
     *   matched_term:?string,
     *   catalog_name:?string,
     *   understanding:?array{raw:string, means:string, source:string},
     *   auto_ok:bool,
     *   notes:list<string>
     * }
     */
    public function matchFamily(string $normalized): array
    {
        $notes = [];
        $tokens = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: []));
        $joined = implode(' ', $tokens);

        // Forced ambiguity: مثلثات / bare مثلث without واي|باب → tee + y_tee.
        $forced = $this->forcedAmbiguous($tokens, $joined);
        if ($forced !== []) {
            return [
                'lexicon_family' => null,
                'family' => null,
                'family_candidates' => $forced,
                'matched_term' => $forced[0]['term'] ?? null,
                'catalog_name' => null,
                'understanding' => null,
                'auto_ok' => false,
                'notes' => ['ambiguous_family'],
            ];
        }

        $hits = $this->matchPhrases($tokens);
        if ($hits === []) {
            $hits = $this->fuzzyCandidates($tokens);
            if ($hits === []) {
                return [
                    'lexicon_family' => null,
                    'family' => null,
                    'family_candidates' => [],
                    'matched_term' => null,
                    'catalog_name' => null,
                    'understanding' => null,
                    'auto_ok' => false,
                    'notes' => $notes,
                ];
            }
            $notes[] = 'fuzzy_family';
        }

        return $this->finalizeHit($hits, $notes);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array<string,mixed>>
     */
    protected function forcedAmbiguous(array $tokens, string $joined): array
    {
        if (str_contains($joined, 'واي') || str_contains($joined, 'باب') || str_contains($joined, 'سنترا')) {
            return [];
        }

        $map = (array) config('ocr_lexicon.ambiguous_lemmas', []);
        foreach ($tokens as $token) {
            foreach ($this->morphology->lemmas($token) as $lemma) {
                if (! isset($map[$lemma])) {
                    continue;
                }
                // Only force when the surface looks plural/ambiguous (مثلثات) or bare lemma with no angle words.
                $isPluralSurface = $token !== $lemma || str_ends_with($token, 'ات');
                if (! $isPluralSurface && $token !== 'مثلث') {
                    continue;
                }
                // Bare "مثلث" alone is historically tee — keep ambiguous only for plural forms.
                if ($token === 'مثلث' || ($lemma === 'مثلث' && ! $isPluralSurface)) {
                    continue;
                }

                $out = [];
                foreach ((array) $map[$lemma] as $family) {
                    $out[] = [
                        'family' => (string) $family,
                        'catalog_family' => $this->catalogFamilyFor((string) $family),
                        'term' => $token,
                        'catalog_name' => $this->catalogNameFor((string) $family),
                        'score' => 0.1,
                        'confirmed' => false,
                        'source' => 'ambiguous',
                    ];
                }

                return $out;
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array<string,mixed>>
     */
    protected function matchPhrases(array $tokens): array
    {
        $n = count($tokens);
        $bestLen = 0;
        $hits = [];

        foreach ($this->allEntries() as $entry) {
            $phraseTokens = preg_split('/\s+/u', $entry['term']) ?: [];
            $pLen = count($phraseTokens);
            if ($pLen === 0 || $pLen > $n) {
                continue;
            }
            if ($bestLen > 0 && $pLen < $bestLen) {
                break; // entries sorted longest-first
            }

            for ($i = 0; $i <= $n - $pLen; $i++) {
                $slice = array_slice($tokens, $i, $pLen);
                $ok = true;
                foreach ($phraseTokens as $k => $pt) {
                    if (! $this->tokenEqualsWithMorphology($slice[$k], $pt)) {
                        $ok = false;
                        break;
                    }
                }
                if (! $ok) {
                    continue;
                }

                if ($pLen > $bestLen) {
                    $bestLen = $pLen;
                    $hits = [];
                }

                $hits[] = [
                    'family' => $entry['family'],
                    'catalog_family' => $this->catalogFamilyFor($entry['family']),
                    'term' => $entry['term'],
                    'catalog_name' => $entry['catalog_name'],
                    'score' => 0.0,
                    'confirmed' => $entry['confirmed'],
                    'source' => $entry['source'],
                    'raw_token' => implode(' ', $slice),
                    'phrase_len' => $pLen,
                ];
                break;
            }
        }

        return $hits;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array<string,mixed>>
     */
    protected function fuzzyCandidates(array $tokens): array
    {
        $entries = array_values(array_filter($this->allEntries(), fn ($e) => $e['phrase_len'] === 1));
        $scored = [];

        foreach ($tokens as $token) {
            if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/u', $token) || $this->isUnitToken($token)) {
                continue;
            }

            foreach ($this->morphology->lemmas($token) as $lemma) {
                foreach ($entries as $entry) {
                    $d = $this->distance->distance($lemma, $entry['term']);
                    $max = $this->distance->thresholdForLength(max(mb_strlen($lemma), mb_strlen($entry['term'])));
                    if ($d > $max) {
                        continue;
                    }
                    $key = $entry['family'].'|'.$entry['term'];
                    if (! isset($scored[$key]) || $d < $scored[$key]['score']) {
                        $scored[$key] = [
                            'family' => $entry['family'],
                            'catalog_family' => $this->catalogFamilyFor($entry['family']),
                            'term' => $entry['term'],
                            'catalog_name' => $entry['catalog_name'],
                            'score' => $d,
                            'confirmed' => $entry['confirmed'],
                            'source' => 'fuzzy:'.$entry['source'],
                            'raw_token' => $token,
                        ];
                    }
                }
            }
        }

        $list = array_values($scored);
        usort($list, fn ($a, $b) => $a['score'] <=> $b['score']);

        return array_slice($list, 0, 5);
    }

    /**
     * @param  list<array<string,mixed>>  $hits
     * @return array<string,mixed>
     */
    protected function finalizeHit(array $hits, array $notes): array
    {
        $byFamily = [];
        foreach ($hits as $h) {
            $f = $h['family'];
            if (! isset($byFamily[$f])) {
                $byFamily[$f] = $h;

                continue;
            }
            $cur = $byFamily[$f];
            if ($h['score'] < $cur['score']
                || (abs((float) $h['score'] - (float) $cur['score']) < 0.001 && $h['confirmed'] && ! $cur['confirmed'])) {
                $byFamily[$f] = $h;
            }
        }
        $ranked = array_values($byFamily);
        usort($ranked, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $a['score'] <=> $b['score'];
            }

            // Prefer confirmed.
            return ((int) $b['confirmed']) <=> ((int) $a['confirmed']);
        });

        $best = $ranked[0];
        $runnerUp = $ranked[1] ?? null;
        $autoMax = (float) config('ocr_lexicon.fuzzy.auto_accept_max', 0.35);
        $margin = (float) config('ocr_lexicon.fuzzy.auto_accept_margin', 0.25);

        $autoOk = (bool) $best['confirmed']
            && (float) $best['score'] <= $autoMax
            && (
                $runnerUp === null
                || ((float) $runnerUp['score'] - (float) $best['score']) >= $margin
                || ((float) $best['score'] <= 0.01 && (float) $runnerUp['score'] > 0.01)
            );

        // Multiple exact confirmed families at score 0 → not auto.
        $exactFamilies = array_values(array_filter($ranked, fn ($h) => (float) $h['score'] <= 0.01));
        if (count($exactFamilies) > 1) {
            $autoOk = false;
            $notes[] = 'ambiguous_family';
        }

        // Unconfirmed vocabulary may never auto-accept.
        if (! $best['confirmed']) {
            $autoOk = false;
            $notes[] = 'unconfirmed_term';
        }

        $understanding = null;
        $catalogName = (string) $best['catalog_name'];
        $rawShow = (string) ($best['raw_token'] ?? $best['term']);
        if ($rawShow !== '' && ArabicTextNormalizer::normalize($rawShow) !== ArabicTextNormalizer::normalize($catalogName)) {
            $understanding = [
                'raw' => $rawShow,
                'means' => $catalogName,
                'source' => (string) $best['source'],
            ];
        }

        $lexFamily = $autoOk ? (string) $best['family'] : null;
        // Single clear family still surfaces as family for candidate retrieval even if needs confirmation chip.
        if ($lexFamily === null && count($ranked) === 1 && (float) $best['score'] <= 0.01) {
            $lexFamily = (string) $best['family'];
            // Still not auto product accept if unconfirmed — matcher uses auto_ok.
        }

        $catalogFamily = $lexFamily !== null
            ? $this->catalogFamilyFor($lexFamily)
            : null;

        return [
            'lexicon_family' => $lexFamily,
            'family' => $catalogFamily,
            'family_candidates' => $ranked,
            'matched_term' => (string) $best['term'],
            'catalog_name' => $catalogName,
            'understanding' => $understanding,
            'auto_ok' => $autoOk,
            'notes' => $notes,
        ];
    }

    protected function tokenEqualsWithMorphology(string $hay, string $needle): bool
    {
        if ($hay === $needle) {
            return true;
        }
        // Expand only the written OCR token (hay). Synonym needles stay literal so
        // broken plurals do not reverse-match (طوبو must not equal synonym طوابي).
        foreach ($this->morphology->lemmas($hay) as $lemma) {
            if ($lemma === $needle) {
                return true;
            }
        }

        return false;
    }

    protected function isUnitToken(string $token): bool
    {
        foreach ((array) config('ocr_lexicon.units', []) as $words) {
            foreach ((array) $words as $w) {
                if ($token === ArabicTextNormalizer::normalize((string) $w)) {
                    return true;
                }
            }
        }

        return false;
    }
}
