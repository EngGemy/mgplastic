<?php

namespace App\Services;

use App\Models\Product;
use App\Support\ArabicTextNormalizer;
use Illuminate\Support\Collection;

class InvoiceOcrCartMatcher
{
    public function __construct(
        protected InvoiceCartSheetService $sheet,
    ) {}

    /**
     * Map OCR draft lines onto POS cart lines (matched catalog products).
     *
     * @param  array<string, mixed>  $draft
     * @return array{lines: list<array<string, mixed>>, errors: list<string>, unmatched: list<array<string, mixed>>}
     */
    public function draftToCart(array $draft): array
    {
        $products = Product::query()->with('translations')->orderBy('id')->get();
        $grouped = [];
        $errors = [];
        $unmatched = [];

        foreach ((array) ($draft['lines'] ?? []) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $name = trim((string) ($line['name'] ?? ''));
            $qty = (int) round((float) ($line['qty'] ?? 0));
            $row = $index + 1;

            if ($name === '') {
                $errors[] = "بند {$row}: اسم الصنف فارغ.";

                continue;
            }

            if ($qty < 1) {
                $errors[] = "بند {$row} «{$name}»: الكمية غير صالحة.";

                continue;
            }

            $product = $this->matchProduct($name, $products);
            if (! $product) {
                $unmatched[] = [
                    'name' => $name,
                    'qty' => $qty,
                    'unit_price' => $line['unit_price'] ?? null,
                    'confidence' => $line['confidence'] ?? null,
                ];
                $errors[] = "بند {$row}: لم يُطابق منتج في الكتالوج لـ «{$name}» — أضفه يدوياً من الشبكة.";

                continue;
            }

            $id = (int) $product->id;
            if (! isset($grouped[$id])) {
                $grouped[$id] = ['product' => $product, 'amount' => 0];
            }
            $grouped[$id]['amount'] += $qty;
        }

        $lines = [];
        foreach ($grouped as $group) {
            $lines[] = $this->sheet->cartLine($group['product'], $group['amount']);
        }

        return [
            'lines' => $lines,
            'errors' => $errors,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    protected function matchProduct(string $rawName, Collection $products): ?Product
    {
        $needle = $this->normalizeName($rawName);
        if ($needle === '') {
            return null;
        }

        $exact = [];
        $contains = [];
        $fuzzy = [];

        foreach ($products as $product) {
            $ar = $this->normalizeName((string) ($product->translate('ar')?->name ?? ''));
            $en = $this->normalizeName((string) ($product->translate('en')?->name ?? ''));
            $candidates = array_values(array_filter([$ar, $en]));

            foreach ($candidates as $label) {
                if ($label === $needle) {
                    $exact[$product->id] = $product;

                    continue 2;
                }
            }

            foreach ($candidates as $label) {
                if ($label !== '' && (str_contains($label, $needle) || str_contains($needle, $label))) {
                    $contains[$product->id] = $product;

                    continue 2;
                }
            }

            $best = 0.0;
            foreach ($candidates as $label) {
                if ($label === '') {
                    continue;
                }
                similar_text($needle, $label, $percent);
                $best = max($best, $percent);
            }
            if ($best >= 72) {
                $fuzzy[$product->id] = ['product' => $product, 'score' => $best];
            }
        }

        if (count($exact) === 1) {
            return reset($exact);
        }
        if (count($exact) > 1) {
            return null;
        }

        if (count($contains) === 1) {
            return reset($contains);
        }
        if (count($contains) > 1) {
            return null;
        }

        if ($fuzzy === []) {
            return null;
        }

        uasort($fuzzy, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = reset($fuzzy);
        $second = next($fuzzy);
        if ($second && ($top['score'] - $second['score']) < 5) {
            return null;
        }

        return $top['product'];
    }

    protected function normalizeName(string $name): string
    {
        return ArabicTextNormalizer::normalize($name);
    }
}
