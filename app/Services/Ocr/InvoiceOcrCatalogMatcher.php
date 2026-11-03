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

            // Non-MG brand hint (explicit).
            if ($this->isExplicitNonMgBrand($slots['brand_hint'] ?? null)) {
                $excluded[] = $this->excludeRow($line, 'non_mg_brand', $slots);
                $counts['excluded']++;
                Log::channel('ocr')->info('[OCR] excluded', ['reason' => 'non_mg_brand', 'index' => $index]);

                continue;
            }

            // Carry lexicon understanding chip + alternate family choices.
            if (! empty($slots['understanding'])) {
                $line['understanding'] = $slots['understanding'];
            }
            if (! empty($slots['family_candidates'])) {
                $line['family_candidates'] = $slots['family_candidates'];
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

            // Unknown family → not MG catalog family.
            if ($slots['family'] === null) {
                $excluded[] = $this->excludeRow($line, 'family_not_in_catalog', $slots);
                $counts['excluded']++;
                Log::channel('ocr')->info('[OCR] excluded', ['reason' => 'family_not_in_catalog', 'index' => $index]);

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
        if ($b === 'mg' || str_contains($b, 'mg plastic') || $b === 'ام جي') {
            return false;
        }

        // Any other brand token → non-MG.
        return true;
    }

    /**
     * @param  array<string,mixed>  $line
     * @param  array<string,mixed>  $slots
     * @return array<string,mixed>
     */
    protected function excludeRow(array $line, string $reason, array $slots): array
    {
        return [
            'raw_text' => $line['raw_text'] ?? '',
            'name' => $line['name'] ?? ($line['raw_text'] ?? ''),
            'qty' => $line['qty'] ?? null,
            'reason' => $reason,
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
        $flags = array_values(array_filter(
            (array) ($line['needs_review'] ?? []),
            fn ($f) => ! in_array($f, ['catalog_unmatched', 'needs_choice', 'mg_family_unknown_variant'], true)
        ));
        $line['needs_review'] = $flags;

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
        if ($customerUserId) {
            $alias = (clone $query)->where('customer_user_id', $customerUserId)
                ->where('system_hint', $systemHint)
                ->first();
            if ($alias) {
                $this->touchAlias($alias);

                return Product::query()->with('translations')->find($alias->product_id);
            }
        }

        $alias = $query->where('system_hint', $systemHint)->whereNull('customer_user_id')->first()
            ?? OcrAlias::query()->where('normalized_text', $normalized)->where('system_hint', '')->whereNull('customer_user_id')->first();

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
