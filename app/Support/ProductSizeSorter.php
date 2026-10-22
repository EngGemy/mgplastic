<?php

namespace App\Support;

use App\Models\Product;

/**
 * Numeric size ordering for POS/catalog (1 → 1.5 → 2 بوصة, and mm like 110 → 114).
 */
class ProductSizeSorter
{
    /**
     * @return array{size_value: ?float, size_label: ?string, size_sort: float}
     */
    public static function resolve(?Product $product, ?string $fallbackHaystack = null): array
    {
        $value = null;
        $label = null;

        if ($product && $product->relationLoaded('sizes') && $product->sizes->isNotEmpty()) {
            $size = $product->sizes->sortBy(fn ($s) => [(int) ($s->sort ?? 9999), (string) $s->code])->first();
            $label = trim((string) ($size->label_ar ?: $size->label_en ?: $size->code ?: ''));
            $value = self::parse($label)
                ?? self::parse((string) $size->code)
                ?? (isset($size->sort) ? (float) $size->sort : null);
        }

        if ($value === null) {
            $hay = trim(implode(' ', array_filter([
                $fallbackHaystack,
                $product ? localized_name($product, 'name', '') : null,
            ])));
            $value = self::parse($hay);
            if ($value !== null && $label === null) {
                $label = self::formatLabel($value);
            }
        }

        return [
            'size_value' => $value,
            'size_label' => $label !== '' ? $label : null,
            // Unknown sizes sink to the end for both asc/desc helpers.
            'size_sort' => $value ?? 999999.0,
        ];
    }

    public static function parse(?string $text): ?float
    {
        if ($text === null) {
            return null;
        }

        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return null;
        }

        $t = strtr($t, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '،' => '.',
        ]);

        // 1½ / 1½
        if (preg_match('/(\d+)\s*[½]/u', $t, $m)) {
            return (float) $m[1] + 0.5;
        }

        // 1 1/2
        if (preg_match('/(\d+)\s+(\d+)\s*\/\s*(\d+)/u', $t, $m)) {
            $den = (float) $m[3];

            return $den > 0 ? (float) $m[1] + ((float) $m[2] / $den) : (float) $m[1];
        }

        // 1.5 / 1,5 بوصة|inch|مم
        if (preg_match('/(\d+[.,]\d+)\s*(?:بوص|inch|\bin\b|"|مم|mm)?/u', $t, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        // 1 بوصة | 110 مم | 3"
        if (preg_match('/(?<!\d)(\d+)\s*(?:بوص|inch|\bin\b|"|مم|mm)/u', $t, $m)) {
            return (float) $m[1];
        }

        // Bare number near size words in longer names: "كوع 90 - 3 بوصة"
        if (preg_match('/(\d+[.,]?\d*)\s*(?:بوصة|بوصه|بوص|inch)/u', $t, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }

    public static function formatLabel(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted;
    }

    /**
     * Compare two rows for POS size order. Returns negative/zero/positive.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function compareRows(array $a, array $b, string $sizeOrder = 'asc'): int
    {
        $acc = ((int) (bool) ($a['is_accessories'] ?? $a['is_accessory'] ?? false))
            <=> ((int) (bool) ($b['is_accessories'] ?? $b['is_accessory'] ?? false));
        if ($acc !== 0) {
            // accessories first
            return -$acc;
        }

        $cat = strcmp(
            mb_strtolower((string) ($a['category_name'] ?? '')),
            mb_strtolower((string) ($b['category_name'] ?? '')),
        );
        if ($cat !== 0) {
            return $cat;
        }

        // Tag sections ordered by size in the tag label (كوع 1 بوصة → كوع 1.5).
        $tagSizeA = self::parse((string) ($a['group_name'] ?? $a['tag'] ?? ''))
            ?? (isset($a['size_value']) ? (float) $a['size_value'] : null);
        $tagSizeB = self::parse((string) ($b['group_name'] ?? $b['tag'] ?? ''))
            ?? (isset($b['size_value']) ? (float) $b['size_value'] : null);

        $tagCmp = self::compareNullableFloats($tagSizeA, $tagSizeB, $sizeOrder);
        if ($tagCmp !== 0) {
            return $tagCmp;
        }

        $tagName = strcmp(
            mb_strtolower((string) ($a['group_name'] ?? $a['tag'] ?? '')),
            mb_strtolower((string) ($b['group_name'] ?? $b['tag'] ?? '')),
        );
        if ($tagName !== 0) {
            return $tagName;
        }

        $sizeA = isset($a['size_value']) ? (float) $a['size_value'] : self::parse((string) ($a['name'] ?? ''));
        $sizeB = isset($b['size_value']) ? (float) $b['size_value'] : self::parse((string) ($b['name'] ?? ''));
        $sizeCmp = self::compareNullableFloats($sizeA, $sizeB, $sizeOrder);
        if ($sizeCmp !== 0) {
            return $sizeCmp;
        }

        return strcmp(
            mb_strtolower((string) ($a['name'] ?? '')),
            mb_strtolower((string) ($b['name'] ?? '')),
        );
    }

    protected static function compareNullableFloats(?float $a, ?float $b, string $sizeOrder): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        $cmp = $a <=> $b;

        return $sizeOrder === 'desc' ? -$cmp : $cmp;
    }

    public static function normalizeOrder(?string $raw): string
    {
        $raw = strtolower(trim((string) $raw));

        return in_array($raw, ['desc', 'descending', 'high_to_low', 'largest', 'down'], true)
            ? 'desc'
            : 'asc';
    }
}
