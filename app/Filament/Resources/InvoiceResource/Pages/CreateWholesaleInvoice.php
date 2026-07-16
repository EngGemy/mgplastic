<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\InvoiceNumberService;
use App\Services\WholesaleInvoiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

class CreateWholesaleInvoice extends Page
{
    protected static string $resource = InvoiceResource::class;

    protected static string $view = 'filament.resources.invoice-resource.pages.create-wholesale-invoice';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'فاتورة وارد — مصنع → جملة';

    protected static ?string $title = 'إصدار فاتورة وارد — من المصنع لموزع الجملة';

    protected static ?int $navigationSort = 5;

    protected static bool $shouldRegisterNavigation = false;

    public ?int $wholesaleDistributorId = null;

    public ?int $selectedCategoryId = null;

    public string $search = '';

    /** @var array<string, array{product_id:int, name:string, quantity:int, points_per_unit:float, image:?string}> */
    public array $cart = [];

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function canAccess(array $parameters = []): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    public function mount(): void
    {
        if (auth()->user()?->isWholesaleDistributor()) {
            $this->wholesaleDistributorId = auth()->id();
        }
    }

    public function getNextInvoiceNumberProperty(): string
    {
        return app(InvoiceNumberService::class)->previewNext('wholesale_pos');
    }

    public function getCategoriesProperty(): Collection
    {
        return ProductCategory::query()
            ->with(['translations', 'children.translations'])
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get();
    }

    public function getProductsProperty(): Collection
    {
        return Product::query()
            ->with(['translations', 'category.translations'])
            ->when($this->selectedCategoryId, function ($q) {
                $q->where(function ($qq) {
                    $qq->where('product_category_id', $this->selectedCategoryId)
                        ->orWhereIn('product_category_id', function ($sub) {
                            $sub->from('product_categories')
                                ->select('id')
                                ->where('parent_id', $this->selectedCategoryId);
                        });
                });
            })
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->whereHas('translations', fn ($t) => $t->where('locale', 'ar')->where('name', 'like', $term));
            })
            ->orderBy('id')
            ->limit(60)
            ->get()
            ->map(function (Product $p) {
                $p->display_name = localized_name($p, 'name', "منتج #{$p->id}");

                return $p;
            });
    }

    public function getWholesalersProperty(): Collection
    {
        return User::where('role', 'wholesale_distributor')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function selectCategory(?int $categoryId): void
    {
        $this->selectedCategoryId = $categoryId;
    }

    public function addProduct(int $productId): void
    {
        $product = Product::with('translations')->findOrFail($productId);
        $key = (string) $productId;
        $name = localized_name($product, 'name', "منتج #{$productId}");

        if (isset($this->cart[$key])) {
            $this->cart[$key]['quantity']++;
        } else {
            $this->cart[$key] = [
                'product_id' => $productId,
                'name' => $name,
                'quantity' => 1,
                'points_per_unit' => (float) ($product->points_per_unit ?? 0),
                'image' => $product->main_image,
            ];
        }
    }

    public function incrementQty(string $key): void
    {
        if (isset($this->cart[$key])) {
            $this->cart[$key]['quantity']++;
        }
    }

    public function decrementQty(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        if ($this->cart[$key]['quantity'] <= 1) {
            unset($this->cart[$key]);
        } else {
            $this->cart[$key]['quantity']--;
        }
    }

    public function removeLine(string $key): void
    {
        unset($this->cart[$key]);
    }

    public function getTotalPointsProperty(): int
    {
        return (int) collect($this->cart)->sum(
            fn ($line) => (int) floor($line['quantity'] * $line['points_per_unit'])
        );
    }

    public function issueInvoice(): void
    {
        $user = auth()->user();

        if ($user?->isWholesaleDistributor()) {
            $this->wholesaleDistributorId = $user->id;
        }

        if (! $this->wholesaleDistributorId) {
            Notification::make()->danger()->title('اختر موزع الجملة')->send();

            return;
        }

        $wholesaler = User::findOrFail($this->wholesaleDistributorId);

        if ($user?->isWholesaleDistributor() && (int) $wholesaler->id !== (int) $user->id) {
            Notification::make()->danger()->title('لا يمكنك إصدار فاتورة لموزع آخر')->send();

            return;
        }

        try {
            $invoice = app(WholesaleInvoiceService::class)->issueFromCart(
                wholesaler: $wholesaler,
                lines: array_values($this->cart),
                issuedBy: $user,
            );

            Notification::make()
                ->success()
                ->title('تم إصدار الفاتورة')
                ->body("رقم {$invoice->number} — إجمالي {$this->totalPoints} نقطة")
                ->send();

            $this->redirect(route('admin.invoices.print', $invoice).'?auto=1');
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
        }
    }
}
