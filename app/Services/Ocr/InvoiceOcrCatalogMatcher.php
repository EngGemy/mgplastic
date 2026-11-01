<?php

namespace App\Services\Ocr;

use App\Models\OcrAlias;
use App\Models\Product;
use App\Support\ArabicTextNormalizer;
use Illuminate\Support\Collection;

class InvoiceOcrCatalogMatcher
{
    /**
     * Enrich each draft line with catalog candidates + match decision.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function enrich(array $draft): array
    {
        $products = Product::query()->with('translations')->orderBy('id')->get();
        $auto = (float) config('services.ocr.auto_accept_similarity', 0.9);
        $suggest = (float) config('services.ocr.suggest_similarity', 0.6);

        $lines = [];
        foreach ((array) ($draft['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $raw = trim((string) ($line['raw_text'] ?? $line['name'] ?? ''));
            $normalized = ArabicTextNormalizer::normalize($raw !== '' ? $raw : (string) ($line['name'] ?? ''));

            $aliasProduct = $this->aliasProduct($normalized);
            $candidates = $this->topCandidates($normalized, $products, 3);

            $decision = 'manual';
            $chosenId = null;
            $similarity = 0.0;

            if ($aliasProduct) {
                $decision = 'alias';
                $chosenId = (int) $aliasProduct->id;
                $similarity = 1.0;
                // Ensure alias product is first candidate.
                array_unshift($candidates, [
                    'product_id' => $chosenId,
                    'name' => localized_name($aliasProduct, 'name', 'منتج #'.$chosenId),
                    'similarity' => 1.0,
                ]);
                $candidates = $this->uniqueCandidates($candidates);
            } elseif ($candidates !== []) {
                $similarity = (float) $candidates[0]['similarity'];
                if ($similarity >= $auto) {
                    $decision = 'auto';
                    $chosenId = (int) $candidates[0]['product_id'];
                } elseif ($similarity >= $suggest) {
                    $decision = 'suggest';
                    $chosenId = (int) $candidates[0]['product_id'];
                }
            }

            $line['raw_text'] = $raw !== '' ? $raw : (string) ($line['name'] ?? '');
            $line['normalized_text'] = $normalized;
            $line['candidates'] = $candidates;
            $line['match_decision'] = $decision;
            $line['matched_product_id'] = $chosenId;
            $line['match_similarity'] = $similarity;
            if ($chosenId && empty($line['name'])) {
                $line['name'] = $candidates[0]['name'] ?? $line['raw_text'];
            }

            $lines[] = $line;
        }

        $draft['lines'] = $lines;

        return $draft;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{product_id:int, name:string, similarity:float}>
     */
    public function topCandidates(string $normalized, Collection $products, int $limit = 3): array
    {
        if ($normalized === '') {
            return [];
        }

        $scored = [];
        foreach ($products as $product) {
            $labels = [
                ArabicTextNormalizer::normalize((string) ($product->translate('ar')?->name ?? '')),
                ArabicTextNormalizer::normalize((string) ($product->translate('en')?->name ?? '')),
            ];
            $best = 0.0;
            foreach ($labels as $label) {
                if ($label === '') {
                    continue;
                }
                $best = max($best, $this->similarity($normalized, $label));
            }
            if ($best <= 0) {
                continue;
            }
            $scored[] = [
                'product_id' => (int) $product->id,
                'name' => localized_name($product, 'name', 'منتج #'.$product->id),
                'similarity' => round($best, 4),
            ];
        }

        usort($scored, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($scored, 0, $limit);
    }

    public function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $shorter = min(mb_strlen($a), mb_strlen($b));
            $longer = max(mb_strlen($a), mb_strlen($b));

            return max(0.75, $shorter / max(1, $longer));
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 4);
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

    protected function aliasProduct(string $normalized): ?Product
    {
        if ($normalized === '') {
            return null;
        }

        $alias = OcrAlias::query()->where('normalized_text', $normalized)->first();
        if (! $alias) {
            return null;
        }

        $alias->forceFill([
            'hits' => (int) $alias->hits + 1,
            'last_used_at' => now(),
        ])->save();

        return Product::query()->with('translations')->find($alias->product_id);
    }

    /**
     * @param  list<array{product_id:int, name:string, similarity:float}>  $candidates
     * @return list<array{product_id:int, name:string, similarity:float}>
     */
    protected function uniqueCandidates(array $candidates): array
    {
        $seen = [];
        $out = [];
        foreach ($candidates as $c) {
            $id = (int) $c['product_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $c;
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }
}
