<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductPointsRateChange;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

class ProductPointsChangesReport extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string $view = 'filament.pages.product-points-changes-report';

    protected static ?int $navigationSort = 13;

    public string $search = '';

    /** changed | all | recent */
    public string $filter = 'changed';

    public ?int $selectedProductId = null;

    protected string $paginationTheme = 'tailwind';

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'تقرير تغيرات الأسعار';
    }

    public function getTitle(): string
    {
        return 'تقرير تغيرات أسعار النقاط';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin', 'monitor'], true);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selectedProductId = null;
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
        $this->selectedProductId = null;
    }

    public function selectProduct(int $productId): void
    {
        $this->selectedProductId = $this->selectedProductId === $productId ? null : $productId;
    }

    public function clearSelection(): void
    {
        $this->selectedProductId = null;
    }

    /**
     * @return array{total_changes:int, products_touched:int, up_count:int, down_count:int, last_change_at:?string, edit_url:string}
     */
    public function getSummaryStats(): array
    {
        $totalChanges = ProductPointsRateChange::query()->count();
        $productsTouched = (int) ProductPointsRateChange::query()
            ->selectRaw('count(distinct product_id) as c')
            ->value('c');
        $upCount = ProductPointsRateChange::query()->where('delta_points_per_unit', '>', 0)->count();
        $downCount = ProductPointsRateChange::query()->where('delta_points_per_unit', '<', 0)->count();
        $lastAt = ProductPointsRateChange::query()->max('created_at');

        return [
            'total_changes' => $totalChanges,
            'products_touched' => $productsTouched,
            'up_count' => $upCount,
            'down_count' => $downCount,
            'last_change_at' => $lastAt ? Carbon::parse($lastAt)->diffForHumans() : null,
            'edit_url' => ProductPointsRates::getUrl(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function getProductDossiersProperty(): LengthAwarePaginator
    {
        $lastChanges = ProductPointsRateChange::query()
            ->select('product_id', DB::raw('MAX(created_at) as last_change_at'), DB::raw('COUNT(*) as changes_count'))
            ->groupBy('product_id');

        $query = Product::query()
            ->select('products.*')
            ->with(['translations', 'latestPointsRateChange.author'])
            ->leftJoinSub($lastChanges, 'rate_agg', function ($join) {
                $join->on('rate_agg.product_id', '=', 'products.id');
            })
            ->addSelect([
                'rate_agg.last_change_at',
                DB::raw('COALESCE(rate_agg.changes_count, 0) as points_rate_changes_count'),
            ]);

        if ($this->filter === 'changed') {
            $query->whereNotNull('rate_agg.last_change_at');
        } elseif ($this->filter === 'recent') {
            $query->where('rate_agg.last_change_at', '>=', now()->subDays(30));
        }

        if (filled(trim($this->search))) {
            $query->search(trim($this->search));
        }

        return $query
            ->orderByRaw('rate_agg.last_change_at IS NULL ASC')
            ->orderByDesc('rate_agg.last_change_at')
            ->orderByDesc('products.id')
            ->paginate(12);
    }

    /**
     * @return Collection<int, ProductPointsRateChange>
     */
    public function getSelectedTimelineProperty(): Collection
    {
        if (! $this->selectedProductId) {
            return collect();
        }

        return ProductPointsRateChange::query()
            ->with('author:id,name,role')
            ->where('product_id', $this->selectedProductId)
            ->latest()
            ->limit(50)
            ->get();
    }

    public function getSelectedProductProperty(): ?Product
    {
        if (! $this->selectedProductId) {
            return null;
        }

        return Product::query()
            ->with(['translations', 'latestPointsRateChange'])
            ->withCount('pointsRateChanges')
            ->find($this->selectedProductId);
    }

    public function productEditUrl(Product $product): string
    {
        return ProductResource::getUrl('edit', ['record' => $product]);
    }

    public function ratesEditUrl(?int $productId = null): string
    {
        return ProductPointsRates::getUrl();
    }
}
