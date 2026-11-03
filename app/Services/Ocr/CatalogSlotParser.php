<?php

namespace App\Services\Ocr;

use App\Services\Ocr\Lexicon\OcrLexicon;
use App\Support\ArabicTextNormalizer;

class CatalogSlotParser
{
    public function __construct(
        protected OcrLexicon $lexicon,
        protected SizeDialectParser $sizeDialect,
    ) {}

    /**
     * @return array{
     *   family:?string,
     *   lexicon_family:?string,
     *   family_candidates:list<array<string,mixed>>,
     *   understanding:?array{raw:string, means:string, source:string},
     *   lexicon_auto_ok:bool,
     *   angle:?float,
     *   size_value:?float,
     *   size_unit:?string,
     *   size2_value:?float,
     *   system:?string,
     *   system_source:?string,
     *   variant:array<string,mixed>,
     *   line_no:?int,
     *   normalized:string,
     *   brand_hint:?string,
     *   parse_ok:bool,
     *   parse_notes:list<string>
     * }
     */
    public function parse(string $raw, ?string $brandHint = null): array
    {
        $notes = [];
        $normalized = ArabicTextNormalizer::normalize($raw);
        $lineNo = null;

        if (preg_match('/^([0-9]{1,3})[\s.\-:]+(.+)$/u', $normalized, $m)) {
            $lineNo = (int) $m[1];
            $normalized = trim($m[2]);
        }

        $system = $this->detectSystem($normalized, $systemSource);
        $variant = $this->detectVariants($normalized);

        $lex = $this->lexicon->matchFamily($normalized);
        $notes = array_merge($notes, $lex['notes'] ?? []);
        $family = $lex['family'] ?? null;
        $lexiconFamily = $lex['lexicon_family'] ?? null;
        $familyCandidates = $lex['family_candidates'] ?? [];
        $understanding = $lex['understanding'] ?? null;
        $lexiconAutoOk = (bool) ($lex['auto_ok'] ?? false);

        // Legacy ocr_catalog covers thread/centra/expansion phrases — prefer when more specific.
        // Do not let legacy override an explicit lexicon ambiguity (مثلثات → tee|y_tee).
        $legacy = $this->detectFamilyLegacy($normalized, $variant);
        $lexiconAmbiguous = in_array('ambiguous_family', $notes, true) && count($familyCandidates) > 1;
        if (! $lexiconAmbiguous) {
            $family = $this->preferMoreSpecificFamily($family, $legacy);
            if ($family !== null && $family === $legacy && $legacy !== ($lex['family'] ?? null)) {
                $lexiconFamily = null;
                $understanding = null;
                $lexiconAutoOk = true; // catalog synonym path
                $familyCandidates = [];
                $notes = array_values(array_filter($notes, fn ($n) => ! in_array($n, ['unconfirmed_term', 'ambiguous_family', 'fuzzy_family'], true)));
            }
        }

        $variant = $this->stripFamilyImpliedVariants($family, $variant);

        [$angle, $size, $size2, $unit, $angleNotes] = $this->detectNumbers($normalized, $family);
        $notes = array_merge($notes, $angleNotes);

        // Re-map elbow angle → catalog family after numbers known.
        if (($lexiconFamily === 'elbow' || $family === 'elbow_90' || $family === 'elbow_45') && $angle !== null) {
            $family = $this->lexicon->catalogFamilyFor('elbow', $angle);
            $lexiconFamily = 'elbow';
        }

        if ($size !== null && $size >= 50) {
            $unit = 'mm';
        } elseif ($size !== null && $unit === null) {
            $unit = 'in';
        }

        if ($system === null && $size !== null) {
            if ($unit === 'mm' && in_array((int) $size, [75, 90, 110, 160], true)) {
                $system = 'eu';
                $systemSource = 'size';
            } elseif ($unit === 'in' && in_array($size, [3.0, 4.0, 6.0], true)) {
                $system = 'us';
                $systemSource = 'size';
            } elseif ($unit === 'in' && abs($size - 2.5) < 0.01) {
                $system = 'eu';
                $systemSource = 'size';
            }
        }

        $parseOk = $family !== null && $size !== null;

        if ($family === 'elbow_90' || $family === 'elbow_45') {
            if ($angle === null && $size !== null && in_array((int) $size, [45, 90], true) && $size2 === null) {
                $notes[] = 'ambiguous_angle_or_size';
                $parseOk = false;
            }
        }

        // Ambiguous multi-family from lexicon → not parse_ok for auto cart.
        if (in_array('ambiguous_family', $notes, true) && count($familyCandidates) > 1) {
            $parseOk = false;
            $family = null;
        }

        $brand = $brandHint !== null && trim($brandHint) !== ''
            ? ArabicTextNormalizer::normalize($brandHint)
            : null;

        return [
            'family' => $family,
            'lexicon_family' => $lexiconFamily,
            'family_candidates' => $familyCandidates,
            'understanding' => $understanding,
            'lexicon_auto_ok' => $lexiconAutoOk,
            'angle' => $angle,
            'size_value' => $size,
            'size_unit' => $unit,
            'size2_value' => $size2,
            'system' => $system,
            'system_source' => $systemSource,
            'variant' => $variant,
            'line_no' => $lineNo,
            'normalized' => $normalized,
            'brand_hint' => $brand,
            'parse_ok' => $parseOk,
            'parse_notes' => $notes,
        ];
    }

    /**
     * Parse a catalog product name with optional category-implied system.
     *
     * @return array<string, mixed>
     */
    public function parseCatalogName(string $name, ?int $categoryId = null): array
    {
        $slots = $this->parse($name);
        $map = (array) config('ocr_catalog.category_system', []);
        if ($categoryId !== null && array_key_exists($categoryId, $map) && $slots['system'] === null) {
            $slots['system'] = $map[$categoryId];
            $slots['system_source'] = $slots['system'] !== null ? 'category' : null;
        }

        // Catalog names: "كوع 90 – 1 بوصة" → angle 90, size 1.
        if (in_array($slots['family'], ['elbow_90', 'elbow_45'], true) && $slots['angle'] === null && $slots['size_value'] !== null) {
            $nums = $this->extractNumbers($slots['normalized']);
            if (count($nums) >= 2) {
                $slots['angle'] = $nums[0];
                $slots['size_value'] = $nums[1];
                if (($slots['size_value'] ?? 0) >= 50) {
                    $slots['size_unit'] = 'mm';
                }
                $slots['parse_ok'] = true;
                $slots['parse_notes'] = array_values(array_filter(
                    $slots['parse_notes'],
                    fn ($n) => $n !== 'ambiguous_angle_or_size'
                ));
            } elseif (count($nums) === 1 && in_array((int) $nums[0], [45, 90], true)) {
                // Bare "كوع 90" in catalog usually means elbow_90 with missing size — mark incomplete.
                $slots['angle'] = $nums[0];
                $slots['size_value'] = null;
                $slots['parse_ok'] = false;
                $slots['parse_notes'][] = 'catalog_missing_size';
            }
        }

        // Force family from angle token when name starts with elbow pattern.
        if ($slots['family'] === 'elbow_90' && $slots['angle'] === null) {
            $slots['angle'] = 90.0;
        }
        if ($slots['family'] === 'elbow_45' && $slots['angle'] === null) {
            $slots['angle'] = 45.0;
        }

        return $slots;
    }

    protected function detectSystem(string $normalized, ?string &$source): ?string
    {
        $source = null;
        foreach ((array) config('ocr_catalog.system_words.us', []) as $word) {
            $w = ArabicTextNormalizer::normalize((string) $word);
            if ($w !== '' && $this->fuzzyContains($normalized, $w)) {
                $source = 'text';

                return 'us';
            }
        }
        foreach ((array) config('ocr_catalog.system_words.eu', []) as $word) {
            $w = ArabicTextNormalizer::normalize((string) $word);
            if ($w !== '' && $this->fuzzyContains($normalized, $w)) {
                $source = 'text';

                return 'eu';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    protected function stripFamilyImpliedVariants(?string $family, array $variant): array
    {
        $implied = [
            'male_thread_coupling' => ['male_thread'],
            'female_thread_coupling' => ['female_thread'],
            'female_thread_elbow' => ['female_thread'],
            'door_elbow' => ['door'],
            'door_tee' => ['door'],
            'centra_elbow' => ['centra'],
            'centra_tee' => ['centra'],
            'centra_door_elbow' => ['centra', 'door'],
            'centra_door_tee' => ['centra', 'door'],
        ];
        foreach ($implied[$family] ?? [] as $key) {
            unset($variant[$key]);
        }

        return $variant;
    }

    /** @return array<string, bool|string|float> */
    protected function detectVariants(string $normalized): array
    {
        $out = [];
        foreach ((array) config('ocr_catalog.variant_tokens', []) as $key => $tokens) {
            foreach ((array) $tokens as $token) {
                $t = ArabicTextNormalizer::normalize((string) $token);
                if ($t !== '' && $this->fuzzyContains($normalized, $t)) {
                    $out[$key] = true;
                    break;
                }
            }
        }

        if (preg_match('/\bpar\s*([0-9]+(?:\.[0-9]+)?)\b/iu', $normalized, $m)) {
            $out['par'] = (float) $m[1];
        }

        return $out;
    }

    protected function preferMoreSpecificFamily(?string $lexiconFamily, ?string $legacy): ?string
    {
        if ($legacy === null) {
            return $lexiconFamily;
        }
        if ($lexiconFamily === null) {
            return $legacy;
        }
        if ($lexiconFamily === $legacy) {
            return $legacy;
        }

        $children = [
            'coupling' => ['male_thread_coupling', 'female_thread_coupling', 'expansion_coupling', 'repair_coupling', 'short_coupling', 'drain_assembly'],
            'elbow_90' => ['door_elbow', 'female_thread_elbow', 'centra_elbow', 'centra_door_elbow'],
            'elbow_45' => ['door_elbow', 'female_thread_elbow', 'centra_elbow', 'centra_door_elbow'],
            'tee' => ['door_tee', 'centra_tee', 'centra_door_tee'],
            'wye' => [],
            'end_cap' => [],
            'pipe' => [],
        ];

        foreach ($children as $parent => $specifics) {
            if ($lexiconFamily === $parent && in_array($legacy, $specifics, true)) {
                return $legacy;
            }
        }

        return $lexiconFamily;
    }

    /** @param  array<string, mixed>  $variant */
    protected function detectFamilyLegacy(string $normalized, array $variant): ?string
    {
        // Prefer longer / more specific synonym phrases first (legacy catalog map).
        $families = (array) config('ocr_catalog.families', []);
        $best = null;
        $bestLen = 0;

        foreach ($families as $family => $synonyms) {
            foreach ((array) $synonyms as $syn) {
                $s = ArabicTextNormalizer::normalize((string) $syn);
                if ($s === '') {
                    continue;
                }
                if ($this->fuzzyContains($normalized, $s)) {
                    $len = mb_strlen($s);
                    if ($family === 'elbow_90' && $s === 'كوع') {
                        if (! empty($variant['door']) || ! empty($variant['centra']) || ! empty($variant['female_thread'])) {
                            continue;
                        }
                        if (! preg_match('/كوع\s*[0-9]/u', $normalized) && ! preg_match('/\b[0-9]/u', $normalized)) {
                            continue;
                        }
                    }
                    if ($len > $bestLen) {
                        $best = (string) $family;
                        $bestLen = $len;
                    }
                }
            }
        }

        if ($best === 'elbow_90' && ! empty($variant['door']) && empty($variant['centra'])) {
            return 'door_elbow';
        }
        if ($best === 'tee' && ! empty($variant['door']) && empty($variant['centra'])) {
            return 'door_tee';
        }

        return $best;
    }

    /**
     * @return array{0:?float,1:?float,2:?float,3:?string,4:list<string>}
     */
    protected function detectNumbers(string $normalized, ?string $family): array
    {
        $notes = [];

        // Reducer: من X الى Y
        if (preg_match('/من\s*([0-9]+(?:\.[0-9]+)?)\s*(?:الى|الي|إلى)\s*([0-9]+(?:\.[0-9]+)?)/u', $normalized, $m)) {
            $unit = $this->sizeDialect->detectUnit($normalized);

            return [null, (float) $m[1], (float) $m[2], $unit, $notes];
        }

        [$dialectSize, $dialectSize2, $unit, $dialectNotes] = $this->sizeDialect->parse($normalized);
        $notes = array_merge($notes, $dialectNotes);

        $nums = $this->extractNumbers($normalized);
        // Prefer dialect-adjusted primary size when ونص/وربع / number-words applied.
        if ($dialectSize !== null && $nums === []) {
            $nums = [$dialectSize];
            if ($dialectSize2 !== null) {
                $nums[] = $dialectSize2;
            }
        } elseif ($dialectSize !== null && $nums !== [] && abs($nums[0] - $dialectSize) > 0.01) {
            $nums[0] = $dialectSize;
        }

        if ($family === 'elbow_90' || $family === 'elbow_45' || $family === 'door_elbow' || str_starts_with((string) $family, 'centra_') || $family === null && $this->looksLikeElbow($normalized)) {
            // Treat as elbow number layout when lexicon mapped elbow or text has elbow cues.
            $elbowFamily = $family ?? 'elbow_90';
            if (count($nums) >= 2) {
                $angle = $nums[0];
                $size = $nums[1];
                if (! in_array((int) $angle, [45, 90], true) && in_array((int) $nums[1], [45, 90], true)) {
                    $angle = $nums[1];
                    $size = $nums[0];
                }

                return [$angle, $size, null, $unit, $notes];
            }
            if (count($nums) === 1) {
                $n = $nums[0];
                if (in_array((int) $n, [45, 90], true)) {
                    $notes[] = 'ambiguous_angle_or_size';

                    return [$n, null, null, $unit, $notes];
                }

                return [$elbowFamily === 'elbow_45' ? 45.0 : 90.0, $n, null, $unit, $notes];
            }
        }

        // English "elbow 90 1 inch" — family may already be elbow_90 from lexicon.
        if (in_array($family, ['elbow_90', 'elbow_45', 'door_elbow'], true) === false
            && preg_match('/\belbow\b/i', $normalized)
            && count($nums) >= 2) {
            return [$nums[0], $nums[1], null, $unit, $notes];
        }

        if (count($nums) >= 2 && ($family === 'reducer' || $family === 'adapter_114_110')) {
            return [null, $nums[0], $nums[1], $unit, $notes];
        }

        if (count($nums) >= 1) {
            return [null, $nums[0], $nums[1] ?? null, $unit, $notes];
        }

        return [null, null, null, $unit, $notes];
    }

    protected function sizeDialectHasModifier(string $normalized): bool
    {
        foreach (array_merge(
            (array) config('ocr_lexicon.size_words.half_add', []),
            (array) config('ocr_lexicon.size_words.quarter_add', []),
        ) as $w) {
            $t = ArabicTextNormalizer::normalize((string) $w);
            if ($t !== '' && str_contains($normalized, $t)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeElbow(string $normalized): bool
    {
        return str_contains($normalized, 'كوع')
            || str_contains($normalized, 'اكواع')
            || (bool) preg_match('/\belbow\b/i', $normalized);
    }

    /** @return list<float> */
    protected function extractNumbers(string $normalized): array
    {
        preg_match_all('/[0-9]+(?:\.[0-9]+)?/u', $normalized, $m);

        return array_map('floatval', $m[0] ?? []);
    }

    protected function fuzzyContains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }
        if (str_contains($haystack, $needle)) {
            return true;
        }

        // Token-level Levenshtein ≤ 1 for words ≥ 4 letters.
        $hayTokens = preg_split('/\s+/u', $haystack) ?: [];
        $needleTokens = preg_split('/\s+/u', $needle) ?: [];
        if ($needleTokens === []) {
            return false;
        }

        // Single-token fuzzy
        if (count($needleTokens) === 1) {
            $n = $needleTokens[0];
            if (mb_strlen($n) < 4) {
                return false;
            }
            foreach ($hayTokens as $h) {
                if (mb_strlen($h) < 4) {
                    continue;
                }
                if (levenshtein($this->toAsciiKey($h), $this->toAsciiKey($n)) <= 1) {
                    return true;
                }
                // Arabic-aware: compare mb strings via similar length + one edit approx
                if (abs(mb_strlen($h) - mb_strlen($n)) <= 1 && $this->mbLevenshtein($h, $n) <= 1) {
                    return true;
                }
            }

            return false;
        }

        // Multi-token: each needle token must fuzzy-match some haystack token in order.
        $start = 0;
        foreach ($needleTokens as $nt) {
            $found = false;
            for ($i = $start; $i < count($hayTokens); $i++) {
                $h = $hayTokens[$i];
                if ($h === $nt || (mb_strlen($nt) >= 4 && $this->mbLevenshtein($h, $nt) <= 1)) {
                    $found = true;
                    $start = $i + 1;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    protected function toAsciiKey(string $s): string
    {
        return substr(preg_replace('/\s+/', '', $s) ?? $s, 0, 255);
    }

    protected function mbLevenshtein(string $a, string $b): int
    {
        $aChars = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bChars = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($aChars);
        $m = count($bChars);
        if ($n === 0) {
            return $m;
        }
        if ($m === 0) {
            return $n;
        }
        if (abs($n - $m) > 1) {
            return 99;
        }
        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $curr = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $aChars[$i - 1] === $bChars[$j - 1] ? 0 : 1;
                $curr[$j] = min($prev[$j] + 1, $curr[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $curr;
        }

        return (int) $prev[$m];
    }
}
