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

    public ?int $quoteScopeParentId = null;

    public ?int $quoteScopeCategoryId = null;

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

    public function applyAllProducts(): void
    {
        $this->quoteScopeParentId = null;
        $this->quoteScopeCategoryId = null;
        $this->setIncluded(fn () => true);
    }

    public function applyParentScope(int $parentId): void
    {
        $this->quoteScopeParentId = $parentId;
        $this->quoteScopeCategoryId = null;
        $this->setIncluded(fn (array $line) => (int) ($line['parent_id'] ?? 0) === $parentId);
        $this->suggestScopeTitle($this->scopeName($parentId, null));
    }

    public function applySectionScope(int $parentId, int $categoryId): void
    {
        $parentId = $parentId ?: null;
        $categoryId = $categoryId ?: null;
        $this->quoteScopeParentId = $parentId;
        $this->quoteScopeCategoryId = $categoryId;
        $this->setIncluded(fn (array $line) => $this->sameSection($line, $parentId, $categoryId));
        $this->suggestScopeTitle($this->scopeName($parentId, $categoryId));
    }

    public function addSectionScope(int $parentId, int $categoryId): void
    {
        $parentId = $parentId ?: null;
        $categoryId = $categoryId ?: null;
        foreach ($this->quoteLines as $index => $line) {
            if ($this->sameSection($line, $parentId, $categoryId)) {
                $this->quoteLines[$index]['included'] = true;
            }
        }
    }

    public string $catalogSearch = '';

    public ?int $browseParentId = null;

    public ?int $browseCategoryId = null;

    public function openCatalogParent(int $parentId): void
    {
        $this->browseParentId = $parentId;
        $this->browseCategoryId = null;
        $this->catalogSearch = '';
    }

    public function openCatalogCategory(int $parentId, int $categoryId): void
    {
        $this->browseParentId = $parentId;
        $this->browseCategoryId = $categoryId;
        $this->catalogSearch = '';
    }

    public function addBrowsedProducts(): void
    {
        $wasEmpty = $this->includedCount() === 0;

        if (trim($this->catalogSearch) !== '') {
            foreach ($this->catalogRows() as $row) {
                $this->quoteLines[$row['_index']]['included'] = true;
            }

            return;
        }

        if ($this->browseCategoryId) {
            $this->addSectionScope((int) $this->browseParentId, (int) $this->browseCategoryId);
            if ($wasEmpty) {
                $this->suggestScopeTitle($this->scopeName((int) $this->browseParentId, (int) $this->browseCategoryId));
            }

            return;
        }

        if ($this->browseParentId) {
            $this->addParentProducts((int) $this->browseParentId);
            if ($wasEmpty) {
                $this->suggestScopeTitle($this->scopeName((int) $this->browseParentId, null));
            }
        }
    }

    public function addParentProducts(int $parentId): void
    {
        foreach ($this->quoteLines as $index => $line) {
            if ((int) ($line['parent_id'] ?? 0) === $parentId) {
                $this->quoteLines[$index]['included'] = true;
            }
        }
    }

    public function toggleLine(int $index): void
    {
        if (! isset($this->quoteLines[$index])) {
            return;
        }

        $this->quoteLines[$index]['included'] = ! $this->lineIsIncluded($this->quoteLines[$index]);
    }

    public function removeSectionAt(int $index): void
    {
        $name = (string) ($this->quoteLines[$index]['category_name'] ?? '');
        if ($name === '') {
            return;
        }

        $this->removeSection($name);
    }

    public function removeSection(string $name): void
    {
        foreach ($this->quoteLines as $index => $line) {
            if (($line['category_name'] ?? '') === $name) {
                $this->quoteLines[$index]['included'] = false;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogRows(): array
    {
        $search = mb_strtolower(trim($this->catalogSearch));

        return collect($this->quoteLines)
            ->map(function (array $line, int $index) {
                $line['_index'] = $index;
                $line['included'] = $this->lineIsIncluded($line);

                return $line;
            })
            ->filter(function (array $line) use ($search) {
                if ($search !== '') {
                    $haystack = mb_strtolower(($line['item_name'] ?? '').' '.($line['category_name'] ?? ''));

                    return str_contains($haystack, $search);
                }

                if ($this->browseCategoryId) {
                    return (int) ($line['category_id'] ?? 0) === (int) $this->browseCategoryId;
                }

                if ($this->browseParentId) {
                    return (int) ($line['parent_id'] ?? 0) === (int) $this->browseParentId;
                }

                return false;
            })
            ->values()
            ->all();
    }

    public bool $showQuotePreview = false;

    public function toggleSection(string $kind, int $id): void
    {
        $indexes = [];
        foreach ($this->quoteLines as $index => $line) {
            $match = $kind === 'child'
                ? (int) ($line['category_id'] ?? 0) === $id
                : (int) ($line['parent_id'] ?? 0) === $id;
            if ($match) {
                $indexes[] = $index;
            }
        }

        if ($indexes === []) {
            return;
        }

        $allOn = collect($indexes)->every(fn (int $index) => $this->lineIsIncluded($this->quoteLines[$index]));
        $wasEmpty = $this->includedCount() === 0;
        foreach ($indexes as $index) {
            $this->quoteLines[$index]['included'] = ! $allOn;
        }

        if ($wasEmpty && ! $allOn) {
            $this->suggestScopeTitle($kind === 'child' ? $this->scopeName(null, $id) : $this->scopeName($id, null));
        }

        $this->showQuotePreview = false;
    }

    public function openQuotePreview(): void
    {
        $this->showQuotePreview = true;
    }

    public function closeQuotePreview(): void
    {
        $this->showQuotePreview = false;
    }

    public function clearQuoteScope(): void
    {
        $this->quoteScopeParentId = null;
        $this->quoteScopeCategoryId = null;
        $this->setIncluded(fn () => false);
        $this->showQuotePreview = false;
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
                'section_name' => trim((string) ($line['category_name'] ?? '')) ?: null,
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
            'included' => false,
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
            'category_name' => $item->section_name ?: $this->categoryLabel($category),
        ];
    }

    /**
     * @param  callable(array<string, mixed>): bool  $matcher
     */
    private function setIncluded(callable $matcher): void
    {
        foreach ($this->quoteLines as $index => $line) {
            $this->quoteLines[$index]['included'] = $matcher($line);
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function sameSection(array $line, ?int $parentId, ?int $categoryId): bool
    {
        if ($categoryId) {
            return (int) ($line['category_id'] ?? 0) === $categoryId;
        }

        if ($parentId) {
            return (int) ($line['parent_id'] ?? 0) === $parentId && empty($line['category_id']);
        }

        return ($line['category_name'] ?? '') === 'سطر يدوي';
    }

    private function scopeName(?int $parentId, ?int $categoryId): ?string
    {
        if ($categoryId) {
            $category = ProductCategory::query()->with(['translations', 'parent.translations'])->find($categoryId);

            return $category ? $this->categoryLabel($category) : null;
        }

        if ($parentId) {
            $category = ProductCategory::query()->with('translations')->find($parentId);

            return $category ? localized_name($category, 'name', 'تصنيف #'.$category->id) : null;
        }

        return null;
    }

    private function suggestScopeTitle(?string $title): void
    {
        if ($title === null || $title === '') {
            return;
        }

        $current = trim((string) ($this->data['title'] ?? ''));
        if ($current === '' || $current === 'قائمة أسعار') {
            $data = $this->data;
            $data['title'] = $title;
            $this->data = $data;
        }
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
    private function includedCount(): int
    {
        return collect($this->quoteLines)->filter(fn (array $line) => $this->lineIsIncluded($line))->count();
    }

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
