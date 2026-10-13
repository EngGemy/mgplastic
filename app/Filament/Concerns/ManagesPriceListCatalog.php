<?php

namespace App\Filament\Concerns;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\PriceListWhatsApp;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Js;

trait ManagesPriceListCatalog
{
    /** @var list<array<string, mixed>> */
    public array $quoteLines = [];

    public bool $whatsAppAfterSave = false;

    public bool $quoteIsNew = true;

    public function loadAutomaticQuoteLines(): void
    {
        $this->quoteIsNew = true;
        $this->quoteLines = $this->linesFromProducts();
    }

    public function loadSavedQuoteLines(): void
    {
        /** @var PriceList $list */
        $list = $this->record;
        $list->load(['items.product.category.parent.translations', 'items.product.category.translations', 'items.product.translations']);

        $this->quoteIsNew = $list->items->isEmpty();
        $this->quoteLines = $this->quoteIsNew
            ? $this->linesFromProducts()
            : $list->items->map(fn (PriceListItem $item) => $this->lineFromItem($item))->values()->all();
    }

    public function addMissingProducts(): void
    {
        $existing = collect($this->quoteLines)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
        $added = 0;
        foreach ($this->linesFromProducts() as $line) {
            if (in_array((int) $line['product_id'], $existing, true)) {
                continue;
            }
            $line['included'] = true;
            $this->quoteLines[] = $line;
            $added++;
        }

        Notification::make()
            ->title($added > 0 ? 'تمت إضافة '.$added.' منتج جديد للمراجعة' : 'كل المنتجات موجودة في العرض')
            ->success()
            ->send();
    }

    public function addManualQuoteLine(): void
    {
        $this->quoteLines[] = [
            'product_id' => null,
            'item_name' => '',
            'pieces_per_box' => null,
            'piece_price_lyd' => 0,
            'piece_price_aed' => 0,
            'box_price_lyd' => 0,
            'box_price_aed' => 0,
            'included' => true,
            'parent_id' => null,
            'category_id' => null,
            'category_name' => 'سطر يدوي',
        ];
    }

    public function syncQuoteLines(): void
    {
        if ($this->quoteLines === [] || ! $this->record instanceof PriceList) {
            return;
        }

        $rows = [];
        $sort = 0;
        foreach ($this->quoteLines as $line) {
            if (! $this->lineIsIncluded($line)) {
                continue;
            }
            $name = trim((string) ($line['item_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $sort++;
            $rows[] = [
                'product_id' => filled($line['product_id'] ?? null) ? (int) $line['product_id'] : null,
                'item_name' => $name,
                'pieces_per_box' => ($line['pieces_per_box'] ?? '') === '' || $line['pieces_per_box'] === null
                    ? null
                    : (int) $line['pieces_per_box'],
                'piece_price_lyd' => $this->nullableNumber($line['piece_price_lyd'] ?? 0),
                'piece_price_aed' => $this->nullableNumber($line['piece_price_aed'] ?? 0),
                'box_price_lyd' => $this->nullableNumber($line['box_price_lyd'] ?? 0),
                'box_price_aed' => $this->nullableNumber($line['box_price_aed'] ?? 0),
                'sort' => $sort,
            ];
        }

        $this->record->items()->delete();
        foreach ($rows as $row) {
            $this->record->items()->create($row);
        }
    }

    public function openWhatsAppIfRequested(): void
    {
        if (! $this->whatsAppAfterSave || ! $this->record instanceof PriceList) {
            return;
        }

        $this->openQuoteWhatsApp();
    }

    public function openQuoteWhatsApp(): void
    {
        if (! $this->record instanceof PriceList) {
            return;
        }

        $list = $this->record->fresh();
        $share = URL::temporarySignedRoute('price-lists.share', now()->addDays(30), $list);
        $url = PriceListWhatsApp::url($list->customer_phone, PriceListWhatsApp::message($list, $share));

        if ($url === null) {
            Notification::make()
                ->title('أضف هاتف العميل لإرسال واتساب')
                ->warning()
                ->send();

            return;
        }

        $this->js('window.open('.Js::from($url).', "_blank", "noopener")');
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function quoteParents(): array
    {
        return ProductCategory::query()
            ->with('translations')
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductCategory $category) => [
                'id' => $category->id,
                'name' => localized_name($category, 'name', 'تصنيف #'.$category->id),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, parent_id: int, name: string}>
     */
    public function quoteChildren(): array
    {
        return ProductCategory::query()
            ->with('translations')
            ->whereNotNull('parent_id')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductCategory $category) => [
                'id' => $category->id,
                'parent_id' => (int) $category->parent_id,
                'name' => localized_name($category, 'name', 'تصنيف #'.$category->id),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linesFromProducts(): array
    {
        return Product::query()
            ->with(['translations', 'category.translations', 'category.parent.translations'])
            ->orderBy('product_category_id')
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product) => $this->lineFromProduct($product))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function lineFromProduct(Product $product): array
    {
        $category = $product->category;

        return array_merge($product->quoteLinePayload(), [
            'piece_price_lyd' => $this->quoteMoney($product->piece_price_lyd),
            'piece_price_aed' => $this->quoteMoney($product->piece_price_aed),
            'box_price_lyd' => $this->quoteMoney($product->box_price_lyd),
            'box_price_aed' => $this->quoteMoney($product->box_price_aed),
            'included' => true,
            'parent_id' => $category?->parent_id ?: $category?->id,
            'category_id' => $category?->parent_id ? $category->id : null,
            'category_name' => $this->categoryLabel($category),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lineFromItem(PriceListItem $item): array
    {
        $category = $item->product?->category;

        return [
            'product_id' => $item->product_id,
            'item_name' => $item->item_name,
            'pieces_per_box' => $item->pieces_per_box,
            'piece_price_lyd' => $this->quoteMoney($item->piece_price_lyd),
            'piece_price_aed' => $this->quoteMoney($item->piece_price_aed),
            'box_price_lyd' => $this->quoteMoney($item->box_price_lyd),
            'box_price_aed' => $this->quoteMoney($item->box_price_aed),
            'included' => true,
            'parent_id' => $category?->parent_id ?: $category?->id,
            'category_id' => $category?->parent_id ? $category->id : null,
            'category_name' => $this->categoryLabel($category),
        ];
    }

    private function categoryLabel(?ProductCategory $category): string
    {
        if (! $category) {
            return 'بدون تصنيف';
        }

        $name = localized_name($category, 'name', 'تصنيف #'.$category->id);
        if ($category->parent) {
            $name = localized_name($category->parent, 'name', 'تصنيف').' · '.$name;
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineIsIncluded(array $line): bool
    {
        return ! in_array($line['included'] ?? true, [false, 0, '0', 'false', 'off', '', null], true);
    }

    private function nullableNumber(mixed $value): float|int|string
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return $value;
    }

    private function quoteMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $number = (float) $value;
        if (abs($number - round($number)) < 0.0005) {
            return (string) (int) round($number);
        }

        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }
}
