<?php

namespace App\Support;

use App\Models\Product;

/**
 * Numeric size ordering for POS/catalog.
 *
 * Inch sections: 1 → 1.5 → 3 → 6 بوصه
 * Then mm sections: 110 مم → 114 مم (after all inches).
 */
class ProductSizeSorter
{
    /**
     * @return array{
     *   size_value: ?float,
     *   size_label: ?string,
     *   size_unit: ?string,
     *   size_sort: float
     * }
     */
    public static function resolve(?Product $product, ?string $fallbackHaystack = null): array
    {
        $parsed = null;

        if ($product && $product->relationLoaded('sizes') && $product->sizes->isNotEmpty()) {
            $size = $product->sizes
                ->sortBy(fn ($s) => [(int) ($s->sort ?? 9999), (string) $s->code])
                ->first();
            $label = trim((string) ($size->label_ar ?: $size->label_en ?: $size->code ?: ''));
            $parsed = self::parseDetailed($label)
                ?? self::parseDetailed((string) $size->code);
        }

        if ($parsed === null) {
            $hay = trim(implode(' ', array_filter([
                $fallbackHaystack,
                $product ? localized_name($product, 'name', '') : null,
            ])));
            $parsed = self::parseDetailed($hay);
        }

        if ($parsed === null) {
            return [
                'size_value' => null,
                'size_label' => null,
                'size_unit' => null,
                'size_sort' => 999999.0,
            ];
        }

        return [
            'size_value' => $parsed['value'],
            'size_label' => $parsed['label'],
            'size_unit' => $parsed['unit'],
            'size_sort' => $parsed['sort'],
        ];
    }

    /** @deprecated Use parseDetailed / resolve */
    public static function parse(?string $text): ?float
    {
        return self::parseDetailed($text)['value'] ?? null;
    }

    /**
     * Prefer the number attached to بوصه/مم (not elbow angle like «كوع 90»).
     *
     * @return array{value: float, unit: string, label: string, sort: float}|null
     */
    public static function parseDetailed(?string $text): ?array
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
            'ـ' => '',
        ]);
        // Normalize common inch spellings.
        $t = str_replace(['بوصة', 'بوصه', 'بوص'], 'بوصه', $t);

        // 1½
        if (preg_match_all('/(\d+)\s*[½]/u', $t, $mm, PREG_SET_ORDER)) {
            $m = $mm[array_key_last($mm)];
            $value = (float) $m[1] + 0.5;

            return self::pack($value, 'inch', self::formatLabel($value).' بوصه');
        }

        // 1 1/2
        if (preg_match_all('/(\d+)\s+(\d+)\s*\/\s*(\d+)/u', $t, $mm, PREG_SET_ORDER)) {
            $m = $mm[array_key_last($mm)];
            $den = (float) $m[3];
            $value = $den > 0 ? (float) $m[1] + ((float) $m[2] / $den) : (float) $m[1];

            return self::pack($value, 'inch', self::formatLabel($value).' بوصه');
        }

        // Explicit mm first (so «110 مم» never competes with inches).
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:مم|mm)\b/u', $t, $mm, PREG_SET_ORDER)) {
            $m = $mm[array_key_last($mm)];
            $value = (float) str_replace(',', '.', $m[1]);

            return self::pack($value, 'mm', self::formatLabel($value).' مم');
        }

        // Explicit inch / بوصه / "
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:بوصه|inch|\bin\b|")/u', $t, $mm, PREG_SET_ORDER)) {
            $m = $mm[array_key_last($mm)];
            $value = (float) str_replace(',', '.', $m[1]);

            return self::pack($value, 'inch', self::formatLabel($value).' بوصه');
        }

        // Whole label is only a number (size master data): «1.5» / «3»
        if (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*$/u', $t, $m)) {
            $value = (float) str_replace(',', '.', $m[1]);
            // Heuristic: large numbers without unit → mm catalog sizes.
            $unit = $value >= 20 ? 'mm' : 'inch';
            $suffix = $unit === 'mm' ? ' مم' : ' بوصه';

            return self::pack($value, $unit, self::formatLabel($value).$suffix);
        }

        return null;
    }

    /**
     * @return array{value: float, unit: string, label: string, sort: float}
     */
    protected static function pack(float $value, string $unit, string $label): array
    {
        // Inches occupy 0…9999; mm after that so: 1 → 1.5 → 3 → 6 then 110 مم.
        $sort = $unit === 'mm'
            ? 10000.0 + $value
            : $value;

        return [
            'value' => $value,
            'unit' => $unit,
            'label' => $label,
            'sort' => $sort,
        ];
    }

    public static function formatLabel(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Stable section key for accordion (size-only groups).
     */
    public static function sizeGroupKey(?float $value, ?string $unit, ?string $label): string
    {
        if ($value === null) {
            return 'size:other';
        }

        $unit = $unit ?: 'inch';

        return 'size:'.$unit.':'.self::formatLabel($value);
    }

    /**
     * Compare two rows / tag sections for size order.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function compareRows(array $a, array $b, string $sizeOrder = 'asc'): int
    {
        $acc = ((int) (bool) ($a['is_accessories'] ?? $a['is_accessory'] ?? false))
            <=> ((int) (bool) ($b['is_accessories'] ?? $b['is_accessory'] ?? false));
        if ($acc !== 0) {
            return -$acc;
        }

        $cat = strcmp(
            mb_strtolower((string) ($a['category_name'] ?? '')),
            mb_strtolower((string) ($b['category_name'] ?? '')),
        );
        if ($cat !== 0) {
            return $cat;
        }

        $sortA = self::rowSortKey($a);
        $sortB = self::rowSortKey($b);
        $sizeCmp = self::compareNullableFloats($sortA, $sortB, $sizeOrder);
        if ($sizeCmp !== 0) {
            return $sizeCmp;
        }

        $tagName = strcmp(
            mb_strtolower((string) ($a['group_name'] ?? $a['tag'] ?? $a['size_label'] ?? '')),
            mb_strtolower((string) ($b['group_name'] ?? $b['tag'] ?? $b['size_label'] ?? '')),
        );
        if ($tagName !== 0) {
            return $tagName;
        }

        return strcmp(
            mb_strtolower((string) ($a['name'] ?? '')),
            mb_strtolower((string) ($b['name'] ?? '')),
        );
    }

    /**
     * Prefer the number written in the tag name (source of truth for accordion order).
     *
     * @param  array<string, mixed>  $row
     */
    public static function rowSortKey(array $row): ?float
    {
        $fromTag = self::parseDetailed((string) ($row['tag'] ?? $row['group_name'] ?? ''));
        if ($fromTag !== null) {
            return $fromTag['sort'];
        }

        if (isset($row['size_sort']) && is_numeric($row['size_sort'])) {
            return (float) $row['size_sort'];
        }

        $fromLabel = self::parseDetailed((string) ($row['size_label'] ?? ''));
        if ($fromLabel !== null) {
            return $fromLabel['sort'];
        }

        if (isset($row['size_value']) && is_numeric($row['size_value'])) {
            $unit = (string) ($row['size_unit'] ?? 'inch');

            return $unit === 'mm'
                ? 10000.0 + (float) $row['size_value']
                : (float) $row['size_value'];
        }

        return self::parseDetailed((string) ($row['name'] ?? ''))['sort'] ?? null;
    }

    /**
     * Sort tag accordion sections by the number inside the tag name
     * («1 بوصه» → «1.5 بوصه» → «3 بوصه» → «6 بوصه» → «110 مم»).
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    public static function sortTagGroupsByNumber(array $groups, string $sizeOrder = 'asc'): array
    {
        $sizeOrder = self::normalizeOrder($sizeOrder);

        usort($groups, function (array $a, array $b) use ($sizeOrder) {
            $nameA = (string) ($a['tag'] ?? $a['name'] ?? $a['group_name'] ?? '');
            $nameB = (string) ($b['tag'] ?? $b['name'] ?? $b['group_name'] ?? '');

            $parsedA = self::parseDetailed($nameA);
            $parsedB = self::parseDetailed($nameB);

            $cmp = self::compareNullableFloats(
                $parsedA['sort'] ?? null,
                $parsedB['sort'] ?? null,
                $sizeOrder,
            );
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp(mb_strtolower($nameA), mb_strtolower($nameB));
        });

        return array_values($groups);
    }

    /** @param  list<array<string, mixed>>  $groups */
    public static function sortGroupsBySize(array $groups, string $sizeOrder = 'asc'): array
    {
        return self::sortTagGroupsByNumber($groups, $sizeOrder);
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
