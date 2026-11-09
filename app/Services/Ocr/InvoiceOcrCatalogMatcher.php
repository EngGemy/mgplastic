<?php

namespace App\Services\Ocr;

use App\Models\OcrAlias;
use App\Models\Product;
use App\Support\ArabicTextNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class InvoiceOcrCatalogMatcher
{
    public function __construct(protected CatalogSlotParser $parser) {}

    /**
     * Enrich draft lines with structured catalog match + excluded[] for non-MG.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function enrich(array $draft, ?string $invoiceSystemDefault = null, ?int $customerUserId = null): array
    {
        $products = Product::query()
            ->with('translations')
            ->whereNotNull('catalog_family')
            ->orderBy('id')
            ->get();

        // Fallback: if nothing derived yet, still load all for soft matching via on-the-fly parse.
        if ($products->isEmpty()) {
            $products = Product::query()->with('translations')->orderBy('id')->get();
            $products = $products->map(function (Product $p) {
                if ($p->catalog_family) {
                    return $p;
                }
                $name = (string) ($p->translate('ar')?->name ?? '');
                $slots = $this->parser->parseCatalogName($name, $p->product_category_id ? (int) $p->product_category_id : null);
                if ($slots['parse_ok']) {
                    $p->catalog_family = $slots['family'];
                    $p->angle = $slots['angle'];
                    $p->size_value = $slots['size_value'];
                    $p->size_unit = $slots['size_unit'];
                    $p->size2_value = $slots['size2_value'];
                    $p->system = $slots['system'];
                    $p->variant = $slots['variant'];
                }

                return $p;
            })->filter(fn (Product $p) => filled($p->catalog_family))->values();
        }

        $lines = [];
        $excluded = [];
        $counts = [
            'raw' => 0,
            'parsed' => 0,
            'matched' => 0,
            'needs_choice' => 0,
            'excluded' => 0,
        ];

        foreach ((array) ($draft['lines'] ?? []) as $index => $line) {
            $counts['raw']++;
            if (! is_array($line)) {
                Log::channel('ocr')->warning('[OCR] line_dropped', [
                    'stage' => 'catalog_match',
                    'index' => $index,
                    'reason' => 'non_array_line',
                ]);

                continue;
            }

            $raw = trim((string) ($line['raw_text'] ?? $line['name'] ?? ''));
            $brandHint = isset($line['brand']) ? (string) $line['brand'] : null;
            $primaryText = $raw !== '' ? $raw : (string) ($line['name'] ?? '');
            $slots = $this->parser->parse($primaryText, $brandHint);
            $resolvedVia = 'primary';

            // User forced a lexicon family (ambiguous مثلثات → tee|y_tee choice).
            $forcedLex = isset($line['force_lexicon_family']) ? (string) $line['force_lexicon_family'] : '';
            if ($forcedLex !== '') {
                $slots['lexicon_family'] = $forcedLex;
                $slots['family'] = app(\App\Services\Ocr\Lexicon\OcrLexicon::class)->catalogFamilyFor($forcedLex, $slots['angle'] ?? null);
                $slots['lexicon_auto_ok'] = true;
                $slots['family_candidates'] = [];
                $slots['understanding'] = [
                    'raw' => $primaryText,
                    'means' => app(\App\Services\Ocr\Lexicon\OcrLexicon::class)->catalogNameFor($forcedLex),
                    'source' => 'user_choice',
                ];
                $resolvedVia = 'user_family';
            }

            // Try Gemini alternate readings when primary has no family.
            if (($slots['family'] ?? null) === null && empty($slots['family_candidates']) && $forcedLex === '') {
                foreach (array_slice((array) ($line['alternatives'] ?? []), 0, 2) as $alt) {
                    $altText = trim((string) $alt);
                    if ($altText === '') {
                        continue;
                    }
                    $altSlots = $this->parser->parse($altText, $brandHint);
                    if (($altSlots['family'] ?? null) !== null || ! empty($altSlots['family_candidates'])) {
                        $slots = $altSlots;
                        $resolvedVia = 'alternative';
                        Log::channel('ocr')->info('[OCR] alternative_resolved', [
                            'index' => $index,
                            'alternative' => mb_substr($altText, 0, 80),
                            'family' => $slots['family'] ?? null,
                        ]);
                        break;
                    }
                }
            }
            $line['resolved_via'] = $resolvedVia;
            $counts['parsed']++;

            // Invoice-level default fills ambiguous system only.
            if ($slots['system'] === null && in_array($invoiceSystemDefault, ['us', 'eu'], true)) {
                if ($this->sizeIsAmbiguous($slots['size_value'], $slots['size_unit'])) {
                    $slots['system'] = $invoiceSystemDefault;
                    $slots['system_source'] = 'invoice_default';
                }
            }

            $line['raw_text'] = $raw !== '' ? $raw : (string) ($line['name'] ?? '');
            $line['normalized_text'] = $slots['normalized'];
            $line['slots'] = $slots;
            if ($slots['line_no'] !== null) {
                $line['line_no'] = $slots['line_no'];
            }

            // Alias before family/exclusion — learned shortcuts may have no parseable family.
            $aliasProduct = $this->aliasProduct(
                $slots['normalized'],
                $slots['system'] ?? '',
                $customerUserId
            );
            if ($aliasProduct) {
                $line = $this->applyMatch($line, $aliasProduct, 'alias', [
                    ['product_id' => (int) $aliasProduct->id, 'name' => localized_name($aliasProduct, 'name', 'منتج #'.$aliasProduct->id), 'flags' => ['alias' => true]],
                ], $slots, 'alias');
                $counts['matched']++;
                $lines[] = $line;

                continue;
            }

            // Carry lexicon understanding chip + alternate family choices.
            if (! empty($slots['understanding'])) {
                $line['understanding'] = $slots['understanding'];
            }
            if (! empty($slots['family_candidates'])) {
                $line['family_candidates'] = $slots['family_candidates'];
            }

            // Explicit non-MG brand on THIS line only (MG is a positive signal).
            if ($this->isExplicitNonMgBrand($slots['brand_hint'] ?? null) && empty($slots['mg_brand'])) {
                $excluded[] = $this->excludeRow($line, 'non_mg_brand', $slots, 'مش منتج MG');
                $counts['excluded']++;
                Log::channel('ocr')->info('[OCR] excluded', ['reason' => 'non_mg_brand', 'index' => $index]);

                continue;
            }

            // Known non-MG / non-plumbing word list (قهوة…).
            if ($this->matchesKnownNonMgWord((string) ($slots['normalized'] ?? ''))) {
                $excluded[] = $this->excludeRow($line, 'known_non_mg', $slots, 'مش منتج MG');
                $counts['excluded']++;
                Log::channel('ocr')->info('[OCR] excluded', ['reason' => 'known_non_mg', 'index' => $index]);

                continue;
            }

            // Plumbing / fittings without MG / ام جي on the line → مستبعد (aliases already bypassed).
            $plumbingish = ($slots['family'] ?? null) !== null
                || ! empty($slots['family_candidates'])
                || ! empty($slots['plumbing_signal']);
            if (
                (bool) config('ocr_catalog.require_mg_brand', true)
                && $plumbingish
                && empty($slots['mg_brand'])
            ) {
                $excluded[] = $this->excludeRow($line, 'plumbing_without_mg', $slots, 'بدون علامة MG');
                $counts['excluded']++;
                Log::channel('ocr')->info('[OCR] excluded', ['reason' => 'plumbing_without_mg', 'index' => $index]);

                continue;
            }

            // Ambiguous lexicon families (مثلثات → tee|y_tee): keep line, ask user.
            if ($slots['family'] === null && ! empty($slots['family_candidates'])) {
                $line['match_decision'] = 'needs_choice';
                $line['match_status'] = 'needs_choice';
                $line['matched_product_id'] = null;
                $line['candidates'] = [];
                $line['match_reason'] = 'عائلة غير مؤكدة — اختَر المعنى';
                $flags = array_values((array) ($line['needs_review'] ?? []));
                $flags[] = 'needs_choice';
                $flags[] = 'ambiguous_family';
                $line['needs_review'] = array_values(array_unique($flags));
                $counts['needs_choice']++;
                $lines[] = $line;

                continue;
            }

            // Unknown family: plumbing signal → needs_review (never exclude).
            if ($slots['family'] === null) {
                if (! empty($slots['plumbing_signal'])) {
                    $line = $this->unknownWordNeedsReview($line, $slots, $products);
                    $counts['needs_choice']++;
                    $lines[] = $line;
                    Log::channel('ocr')->info('[OCR] unknown_word_review', [
                        'index' => $index,
                        'size' => $slots['size_value'] ?? null,
                    ]);

                    continue;
                }

                // No family and no plumbing signal (letterhead / garbage) → exclude.
                $excluded[] = $this->excludeRow($line, 'unreadable_or_non_mg', $slots, 'مش مقروء');
                $counts['excluded']++;
                Log::channel('ocr')->info('[OCR] excluded', ['reason' => 'unreadable_or_non_mg', 'index' => $index]);

                continue;
            }

            $candidates = $this->retrieveCandidates($slots, $products);
            $status = $candidates['status'];
            $list = $candidates['candidates'];
            $lexiconAutoOk = (bool) ($slots['lexicon_auto_ok'] ?? true);
            if (! empty($line['understanding_confirmed']) || $forcedLex !== '') {
                $lexiconAutoOk = true;
            }

            if ($status === 'matched' && count($list) === 1 && $lexiconAutoOk) {
                $product = $products->firstWhere('id', $list[0]['product_id']);
                if ($product) {
                    $reason = $this->matchReason($slots);
                    $line = $this->applyMatch($line, $product, 'auto', $list, $slots, $reason);
                    $counts['matched']++;
                    $lines[] = $line;

                    continue;
                }
            }

            // Unconfirmed dialect/English hit: suggest candidates, never auto-accept.
            if (! $lexiconAutoOk && $list !== []) {
                $line['match_decision'] = 'needs_choice';
                $line['match_status'] = 'needs_choice';
                $line['matched_product_id'] = null;
                $line['candidates'] = $list;
                $line['match_reason'] = ! empty($slots['understanding'])
                    ? sprintf('فهمتها: %s = %s — أكّد أو صحّح', $slots['understanding']['raw'], $slots['understanding']['means'])
                    : 'مصطلح غير مؤكد — راجع الاقتراح';
                $flags = array_values((array) ($line['needs_review'] ?? []));
                $flags[] = 'needs_choice';
                $flags[] = 'unconfirmed_term';
                $line['needs_review'] = array_values(array_unique($flags));
                $counts['needs_choice']++;
                $lines[] = $line;

                continue;
            }

            if ($status === 'needs_choice' || ($list !== [] && $status !== 'matched')) {
                $line['match_decision'] = 'needs_choice';
                $line['matched_product_id'] = null;
                $line['candidates'] = $list;
                $line['match_reason'] = $slots['system_source'] === 'invoice_default'
                    ? 'الافتراضي: راجعه'
                    : 'يحتاج اختيار النظام أو المقاس';
                $flags = array_values((array) ($line['needs_review'] ?? []));
                $flags[] = 'needs_choice';
                if ($status === 'unknown_variant') {
                    $flags[] = 'mg_family_unknown_variant';
                    $line['match_status'] = 'mg_family_unknown_variant';
                } else {
                    $line['match_status'] = 'needs_choice';
                }
                $line['needs_review'] = array_values(array_unique($flags));
                $counts['needs_choice']++;
                Log::channel('ocr')->info('[OCR] unresolved', [
                    'index' => $index,
                    'reason' => $line['match_status'],
                    'family' => $slots['family'],
                    'size' => $slots['size_value'],
                ]);
                $lines[] = $line;

                continue;
            }

            // Known MG family but no catalog candidates yet (empty/undervied catalog).
            // Keep visible for manual pick — do not treat as non-MG.
            $line['match_decision'] = 'manual';
            $line['match_status'] = 'mg_family_unknown_variant';
            $line['matched_product_id'] = null;
            $line['candidates'] = [];
            $line['match_reason'] = 'عائلة معروفة بلا مرشح في الكتالوج';
            $flags = array_values((array) ($line['needs_review'] ?? []));
            $flags[] = 'mg_family_unknown_variant';
            $line['needs_review'] = array_values(array_unique($flags));
            $counts['needs_choice']++;
            Log::channel('ocr')->info('[OCR] unresolved', [
                'index' => $index,
                'reason' => 'mg_family_no_candidates',
                'family' => $slots['family'],
            ]);
            $lines[] = $line;
        }

        $draft['lines'] = $lines;
        $draft['excluded'] = array_values(array_merge((array) ($draft['excluded'] ?? []), $excluded));
        $draft['match_counts'] = $counts;
        $draft['accounted_for'] = $counts['matched'] + $counts['needs_choice'] + $counts['excluded'];

        Log::channel('ocr')->info('[OCR] match_counts', $counts);

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  Collection<int, Product>  $products
     * @return array{status:string, candidates:list<array<string,mixed>>}
     */
    public function retrieveCandidates(array $slots, Collection $products): array
    {
        $family = $slots['family'] ?? null;
        if ($family === null) {
            return ['status' => 'empty', 'candidates' => []];
        }

        $pool = $products->filter(fn (Product $p) => $p->catalog_family === $family)->values();
        if ($pool->isEmpty()) {
            return ['status' => 'empty', 'candidates' => []];
        }

        $scored = [];
        foreach ($pool as $product) {
            $flags = [
                'family' => true,
                'size' => $this->sizeEquals($product->size_value, $slots['size_value'])
                    && ($slots['size_unit'] === null || $product->size_unit === null || $product->size_unit === $slots['size_unit']),
                'angle' => $slots['angle'] === null || $product->angle === null || abs((float) $product->angle - (float) $slots['angle']) < 0.01,
                'size2' => $slots['size2_value'] === null || $this->sizeEquals($product->size2_value, $slots['size2_value']),
                'system' => $slots['system'] === null || $product->system === null || $product->system === $slots['system'],
                'variant' => $this->variantCompatible((array) ($product->variant ?? []), (array) ($slots['variant'] ?? [])),
            ];

            // Must share family; size preferred.
            $rank = 0;
            if ($flags['size']) {
                $rank += 40;
            }
            if ($flags['angle']) {
                $rank += 15;
            }
            if ($flags['system']) {
                $rank += 20;
            }
            if ($flags['variant']) {
                $rank += 10;
            }
            if ($flags['size2']) {
                $rank += 5;
            }

            // Plain pipe first when OCR has no variant word (طوبو 4 بوصة → #108 before كاتم/ذهبي).
            $ocrVariant = (array) ($slots['variant'] ?? []);
            $productVariant = (array) ($product->variant ?? []);
            if ($family === 'pipe' && $ocrVariant === []) {
                if ($productVariant === [] || $this->isPlainPipeVariant($productVariant)) {
                    $rank += 25;
                } else {
                    $rank -= 8;
                }
            }

            // Fuzzy name tie-breaker only.
            $label = ArabicTextNormalizer::normalize((string) ($product->translate('ar')?->name ?? ''));
            similar_text($slots['normalized'] ?? '', $label, $pct);
            $rank += min(10, $pct / 10);

            $scored[] = [
                'product_id' => (int) $product->id,
                'name' => localized_name($product, 'name', 'منتج #'.$product->id),
                'system' => $product->system,
                'size_value' => $product->size_value,
                'size_unit' => $product->size_unit,
                'pieces_per_box' => $product->pieces_per_box,
                'points_per_unit' => $product->points_per_unit,
                'main_image' => $product->main_image,
                'flags' => $flags,
                'rank' => $rank,
                'similarity' => round($pct / 100, 4),
            ];
        }

        usort($scored, fn ($a, $b) => $b['rank'] <=> $a['rank']);

        // Exact unique: family+size+angle+variant+system all true and system not null when ambiguous size.
        $exact = array_values(array_filter($scored, function ($c) use ($slots) {
            $f = $c['flags'];
            if (! ($f['family'] && $f['size'] && $f['angle'] && $f['variant'] && $f['size2'])) {
                return false;
            }
            if ($slots['system'] !== null) {
                return $f['system'] && ($c['system'] === $slots['system'] || $c['system'] === null);
            }

            // Unambiguous size implies system already on product; unique across systems still needs choice if both exist.
            return $f['size'];
        }));

        if ($slots['system'] !== null) {
            $exact = array_values(array_filter($exact, fn ($c) => ($c['system'] === null || $c['system'] === $slots['system']) && $c['flags']['size']));
        }

        if (count($exact) === 1 && $slots['size_value'] !== null && ($slots['system'] !== null || ! $this->sizeIsAmbiguous($slots['size_value'], $slots['size_unit']))) {
            // Auto-accept only when family+size+system are all explicit/inferred and unique.
            $systemOk = $slots['system'] !== null || ! $this->sizeIsAmbiguous($slots['size_value'], $slots['size_unit']);
            if ($systemOk && $exact[0]['flags']['family'] && $exact[0]['flags']['size']) {
                return ['status' => 'matched', 'candidates' => [array_slice($exact, 0, 1)[0]]];
            }
        }

        // Ambiguous 1 / 1.5 / 2 → prefer one US + one EU candidate when both exist.
        if ($this->sizeIsAmbiguous($slots['size_value'], $slots['size_unit']) && $slots['system'] === null) {
            $us = null;
            $eu = null;
            foreach ($scored as $c) {
                if (! $c['flags']['size'] || ! $c['flags']['angle'] || ! $c['flags']['variant']) {
                    continue;
                }
                if ($c['system'] === 'us' && $us === null) {
                    $us = $c;
                }
                if ($c['system'] === 'eu' && $eu === null) {
                    $eu = $c;
                }
            }
            $pair = array_values(array_filter([$us, $eu]));
            if (count($pair) >= 1) {
                return ['status' => 'needs_choice', 'candidates' => array_slice($pair, 0, 3)];
            }
        }

        // Relax: variant → system → size
        $relaxed = array_values(array_filter($scored, fn ($c) => $c['flags']['size'] || $c['rank'] >= 40));
        if ($relaxed === []) {
            $relaxed = array_slice($scored, 0, 3);
            if ($relaxed !== []) {
                return ['status' => 'unknown_variant', 'candidates' => $relaxed];
            }

            return ['status' => 'empty', 'candidates' => []];
        }

        if (count($relaxed) === 1 && $slots['system'] !== null && $relaxed[0]['flags']['size'] && $relaxed[0]['flags']['family']) {
            return ['status' => 'matched', 'candidates' => $relaxed];
        }

        return ['status' => 'needs_choice', 'candidates' => array_slice($relaxed, 0, 3)];
    }

    /** @return list<string> */
    public function catalogNamesForPrompt(int $limit = 80): array
    {
        $names = [];
        Product::query()->with('translations')->orderBy('id')->limit(max(1, $limit))->get()
            ->each(function (Product $p) use (&$names) {
                $n = trim((string) ($p->translate('ar')?->name ?? ''));
                if ($n !== '') {
                    $names[] = $n;
                }
            });

        return $names;
    }

    protected function sizeIsAmbiguous(?float $size, ?string $unit): bool
    {
        if ($size === null) {
            return true;
        }
        if ($unit === 'mm') {
            return false;
        }

        return in_array($size, [1.0, 1.5, 2.0], true);
    }

    protected function sizeEquals(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null ? true : ($a !== null && $b !== null && abs((float) $a - (float) $b) < 0.01);
        }

        return abs((float) $a - (float) $b) < 0.01;
    }

    /** @param  array<string,mixed>  $productVariant */
    /** @param  array<string,mixed>  $ocrVariant */
    protected function variantCompatible(array $productVariant, array $ocrVariant): bool
    {
        if ($ocrVariant === []) {
            return true;
        }

        foreach ($ocrVariant as $key => $val) {
            if ($val === true && empty($productVariant[$key])) {
                return false;
            }
        }

        return true;
    }

    protected function isExplicitNonMgBrand(?string $brand): bool
    {
        if ($brand === null || $brand === '') {
            return false;
        }
        $b = ArabicTextNormalizer::normalize($brand);
        if ($b === 'mg' || str_contains($b, 'mg plastic') || $b === 'ام جي' || str_contains($b, 'ام جي بلاست')) {
            return false;
        }

        foreach ((array) config('ocr_catalog.non_mg_brand_tokens', []) as $token) {
            $t = ArabicTextNormalizer::normalize((string) $token);
            if ($t !== '' && ($b === $t || str_contains($b, $t))) {
                return true;
            }
        }

        // Unknown brand tokens from Gemini line.brand are treated as non-MG only when
        // the token looks like a Latin brand (letterhead), not a size/family word.
        if (preg_match('/^[a-z0-9][a-z0-9 ._-]{1,40}$/u', $b) && ! in_array($b, ['mg', 'in', 'mm', 'pipe', 'tee', 'elbow'], true)) {
            return true;
        }

        return false;
    }

    protected function matchesKnownNonMgWord(string $normalized): bool
    {
        $normalized = trim($normalized);
        if ($normalized === '') {
            return false;
        }
        foreach ((array) config('ocr_catalog.known_non_mg_words', []) as $word) {
            $w = ArabicTextNormalizer::normalize((string) $word);
            if ($w === '') {
                continue;
            }
            if ($normalized === $w || preg_match('/(?:^|\s)'.preg_quote($w, '/').'(?:\s|$)/u', $normalized)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string,mixed>  $variant */
    protected function isPlainPipeVariant(array $variant): bool
    {
        foreach (['layers_silent', 'gold', 'gray', 'orange', 'white', 'ac', 'electric'] as $key) {
            if (! empty($variant[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Unknown token + plumbing signal: keep in review with family chips + fuzzy suggestions.
     *
     * @param  array<string,mixed>  $line
     * @param  array<string,mixed>  $slots
     * @param  Collection<int, Product>  $products
     * @return array<string,mixed>
     */
    protected function unknownWordNeedsReview(array $line, array $slots, Collection $products): array
    {
        $line['match_decision'] = 'needs_choice';
        $line['match_status'] = 'unknown_word';
        $line['matched_product_id'] = null;
        $line['match_reason'] = 'كلمة غير معروفة';
        $line['exclude_label'] = null;
        $line['family_chips'] = $this->familyChips();
        $line['candidates'] = $this->fuzzySuggestionsForUnknown($slots, $products);
        $flags = array_values((array) ($line['needs_review'] ?? []));
        $flags[] = 'needs_choice';
        $flags[] = 'unknown_word';
        $line['needs_review'] = array_values(array_unique($flags));

        return $line;
    }

    /** @return list<array{key:string,label:string}> */
    protected function familyChips(): array
    {
        return [
            ['key' => 'pipe', 'label' => 'ماسورة'],
            ['key' => 'elbow', 'label' => 'كوع'],
            ['key' => 'tee', 'label' => 'مثلث'],
            ['key' => 'y_tee', 'label' => 'مثلث واي'],
            ['key' => 'coupling', 'label' => 'وصلة'],
            ['key' => 'reducer', 'label' => 'مخفض'],
        ];
    }

    /**
     * @param  array<string,mixed>  $slots
     * @param  Collection<int, Product>  $products
     * @return list<array<string,mixed>>
     */
    protected function fuzzySuggestionsForUnknown(array $slots, Collection $products): array
    {
        $size = $slots['size_value'] ?? null;
        $unit = $slots['size_unit'] ?? null;
        $pool = $products;
        if ($size !== null) {
            $pool = $products->filter(function (Product $p) use ($size, $unit) {
                if (! $this->sizeEquals($p->size_value, $size)) {
                    return false;
                }
                if ($unit !== null && $p->size_unit !== null && $p->size_unit !== $unit) {
                    return false;
                }

                return true;
            });
        }

        $scored = [];
        $norm = (string) ($slots['normalized'] ?? '');
        foreach ($pool->take(80) as $product) {
            $label = ArabicTextNormalizer::normalize((string) ($product->translate('ar')?->name ?? ''));
            similar_text($norm, $label, $pct);
            $scored[] = [
                'product_id' => (int) $product->id,
                'name' => localized_name($product, 'name', 'منتج #'.$product->id),
                'system' => $product->system,
                'size_value' => $product->size_value,
                'size_unit' => $product->size_unit,
                'pieces_per_box' => $product->pieces_per_box,
                'points_per_unit' => $product->points_per_unit,
                'main_image' => $product->main_image,
                'flags' => ['fuzzy' => true],
                'rank' => $pct,
                'similarity' => round($pct / 100, 4),
            ];
        }
        usort($scored, fn ($a, $b) => $b['rank'] <=> $a['rank']);

        return array_slice($scored, 0, 5);
    }

    /**
     * @param  array<string,mixed>  $line
     * @param  array<string,mixed>  $slots
     * @return array<string,mixed>
     */
    protected function excludeRow(array $line, string $reason, array $slots, ?string $label = null): array
    {
        $label = $label ?? match ($reason) {
            'plumbing_without_mg' => 'بدون علامة MG',
            'non_mg_brand', 'known_non_mg' => 'مش منتج MG',
            'unreadable_or_non_mg', 'family_not_in_catalog' => 'مش مقروء',
            default => 'مش منتج MG',
        };

        return [
            'raw_text' => $line['raw_text'] ?? '',
            'name' => $line['name'] ?? ($line['raw_text'] ?? ''),
            'qty' => $line['qty'] ?? null,
            'reason' => $reason,
            'exclude_label' => $label,
            'slots' => $slots,
            'match_status' => 'not_mg',
        ];
    }

    /**
     * @param  array<string,mixed>  $line
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $slots
     * @return array<string,mixed>
     */
    protected function applyMatch(array $line, Product $product, string $decision, array $candidates, array $slots, string $reason): array
    {
        $line['candidates'] = $candidates;
        $line['match_decision'] = $decision;
        $line['match_status'] = 'matched';
        $line['matched_product_id'] = (int) $product->id;
        $line['match_similarity'] = $candidates[0]['similarity'] ?? 1.0;
        $line['match_reason'] = $reason;
        $line['name'] = localized_name($product, 'name', 'منتج #'.$product->id);
        $line['pieces_per_box'] = $product->pieces_per_box;
        $line['points_per_unit'] = $product->points_per_unit;
        $line['main_image'] = $product->main_image;
        // Unique match is "ready" for bulk approve — strip OCR noise flags (price/qty).
        $flags = array_values(array_filter(
            (array) ($line['needs_review'] ?? []),
            fn ($f) => ! in_array($f, [
                'catalog_unmatched',
                'needs_choice',
                'mg_family_unknown_variant',
                'unknown_word',
                'ambiguous_family',
                'unconfirmed_term',
                'price_missing',
                'qty_missing',
                'ambiguous_roles',
            ], true)
        ));
        $line['needs_review'] = $flags;
        // Do not set approved here — POS requires an explicit approve action.

        return $line;
    }

    /** @param  array<string,mixed>  $slots */
    protected function matchReason(array $slots): string
    {
        return match ($slots['system_source'] ?? null) {
            'text' => 'من الكتابة',
            'size' => 'النظام من المقاس',
            'category' => 'النظام من التصنيف',
            'invoice_default' => 'الافتراضي: راجعه',
            'alias' => 'من اختيارات سابقة',
            default => 'مطابقة فريدة',
        };
    }

    protected function aliasProduct(string $normalized, string $systemHint = '', ?int $customerUserId = null): ?Product
    {
        if ($normalized === '') {
            return null;
        }

        $query = OcrAlias::query()->where('normalized_text', $normalized);

        // Prefer the acting user's private alias (learned from corrections).
        $ownerId = auth()->id();
        if ($ownerId) {
            $userAlias = (clone $query)
                ->where('owner_user_id', $ownerId)
                ->where(function ($q) use ($systemHint) {
                    $q->where('system_hint', $systemHint)->orWhere('system_hint', '');
                })
                ->orderByDesc('hits')
                ->first();
            if ($userAlias?->product_id) {
                $this->touchAlias($userAlias);

                return Product::query()->with('translations')->find($userAlias->product_id);
            }
        }

        if ($customerUserId) {
            $alias = (clone $query)->where('customer_user_id', $customerUserId)
                ->where('system_hint', $systemHint)
                ->first();
            if ($alias) {
                $this->touchAlias($alias);

                return Product::query()->with('translations')->find($alias->product_id);
            }
        }

        $alias = (clone $query)->where('system_hint', $systemHint)
            ->where(function ($q) {
                $q->whereNull('owner_user_id')->orWhere('scope', 'global');
            })
            ->whereNull('customer_user_id')
            ->first()
            ?? OcrAlias::query()->where('normalized_text', $normalized)
                ->where('system_hint', '')
                ->whereNull('customer_user_id')
                ->where(function ($q) {
                    $q->whereNull('owner_user_id')->orWhere('scope', 'global');
                })
                ->first();

        if (! $alias) {
            return null;
        }

        $this->touchAlias($alias);

        return Product::query()->with('translations')->find($alias->product_id);
    }

    protected function touchAlias(OcrAlias $alias): void
    {
        $alias->forceFill([
            'hits' => (int) $alias->hits + 1,
            'last_used_at' => now(),
        ])->save();
    }
}
