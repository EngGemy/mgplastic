<?php

namespace App\Services\Ocr;

use App\Models\Product;
use Illuminate\Support\Collection;

class ProductCatalogAttributeDeriver
{
    public function __construct(protected CatalogSlotParser $parser) {}

    /**
     * @return array{
     *   total:int,
     *   parsed:int,
     *   failed:list<array{id:int,name:string,notes:list<string>}>,
     *   data_issues:list<array{id:?int,note:string}>,
     *   samples:list<array<string,mixed>>
     * }
     */
    public function report(?Collection $products = null): array
    {
        $products ??= Product::query()->with('translations')->orderBy('id')->get();
        $failed = [];
        $parsed = 0;
        $samples = [];

        foreach ($products as $product) {
            $name = (string) ($product->translate('ar')?->name ?? '');
            $slots = $this->parser->parseCatalogName($name, $product->product_category_id ? (int) $product->product_category_id : null);
            if ($slots['parse_ok']) {
                $parsed++;
            } else {
                $failed[] = [
                    'id' => (int) $product->id,
                    'name' => $name,
                    'notes' => $slots['parse_notes'],
                    'family' => $slots['family'],
                    'size' => $slots['size_value'],
                ];
            }
            if (count($samples) < 8) {
                $samples[] = [
                    'id' => (int) $product->id,
                    'name' => $name,
                    'family' => $slots['family'],
                    'angle' => $slots['angle'],
                    'size' => $slots['size_value'],
                    'unit' => $slots['size_unit'],
                    'system' => $slots['system'],
                    'ok' => $slots['parse_ok'],
                ];
            }
        }

        return [
            'total' => $products->count(),
            'parsed' => $parsed,
            'failed' => $failed,
            'data_issues' => (array) config('ocr_catalog.known_data_issues', []),
            'samples' => $samples,
        ];
    }

    /**
     * Idempotent derive. Does not touch names, categories, or prices.
     *
     * @return array{updated:int, skipped:int, failed:int}
     */
    public function apply(bool $dryRun = true): array
    {
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        Product::query()->with('translations')->orderBy('id')->chunkById(100, function ($chunk) use ($dryRun, &$updated, &$skipped, &$failed) {
            foreach ($chunk as $product) {
                $name = (string) ($product->translate('ar')?->name ?? '');
                $slots = $this->parser->parseCatalogName($name, $product->product_category_id ? (int) $product->product_category_id : null);

                if (! $slots['parse_ok']) {
                    $failed++;

                    continue;
                }

                $payload = [
                    'catalog_family' => $slots['family'],
                    'angle' => $slots['angle'],
                    'size_value' => $slots['size_value'],
                    'size_unit' => $slots['size_unit'],
                    'size2_value' => $slots['size2_value'],
                    'system' => $slots['system'],
                    'variant' => $slots['variant'] ?: null,
                    'derived_at' => now(),
                ];

                $same = $product->catalog_family === $payload['catalog_family']
                    && (float) ($product->angle ?? 0) === (float) ($payload['angle'] ?? 0)
                    && (float) ($product->size_value ?? 0) === (float) ($payload['size_value'] ?? 0)
                    && $product->size_unit === $payload['size_unit']
                    && $product->system === $payload['system'];

                if ($same && $product->derived_at) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    $product->forceFill($payload)->save();
                }
                $updated++;
            }
        });

        return compact('updated', 'skipped', 'failed');
    }
}
