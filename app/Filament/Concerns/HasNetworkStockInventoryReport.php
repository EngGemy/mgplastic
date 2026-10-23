<?php

namespace App\Filament\Concerns;

use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\Product;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Support\StorePermissions;
use Illuminate\Support\Collection;
use Livewire\WithPagination;

trait HasNetworkStockInventoryReport
{
    use WithPagination;

    public string $search = '';

    /** all | in_stock | empty */
    public string $stockFilter = 'all';

    /** all | draft | confirmed | points_awarded */
    public string $historyStatus = 'all';

    /** stock | history */
    public string $panelTab = 'stock';

    public ?int $selectedProductId = null;

    protected string $paginationTheme = 'tailwind';

    abstract protected function reportRole(): string;

    public function getHeading(): string
    {
        return '';
    }

    public function updatedSearch(): void
    {
        $this->resetPage('stockPage');
        $this->resetPage('historyPage');
    }

    public function updatedStockFilter(): void
    {
        $this->resetPage('stockPage');
    }

    public function updatedHistoryStatus(): void
    {
        $this->resetPage('historyPage');
    }

    public function setPanelTab(string $tab): void
    {
        if (! in_array($tab, ['stock', 'history'], true)) {
            return;
        }
        $this->panelTab = $tab;
    }

    public function selectProduct(int $productId): void
    {
        $this->selectedProductId = $this->selectedProductId === $productId ? null : $productId;
        if ($this->selectedProductId) {
            $this->panelTab = 'history';
            $this->resetPage('historyPage');
        }
    }

    public function clearProduct(): void
    {
        $this->selectedProductId = null;
    }

    protected function actor(): ?User
    {
        return auth()->user();
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rawStockRows(): Collection
    {
        $user = $this->actor();
        if (! $user) {
            return collect();
        }

        $inventory = app(NetworkInventoryService::class);

        return $this->reportRole() === 'wholesale'
            ? $inventory->stockForWholesaler($user)
            : $inventory->stockForRetailTrader($user);
    }

    /**
     * @return array{
     *   skus:int,
     *   units:int,
     *   points:int,
     *   pending_drafts:int,
     *   confirmed_out:int,
     *   role_label:string
     * }
     */
    public function getSummaryStats(): array
    {
        $rows = $this->rawStockRows();
        $user = $this->actor();

        $pending = 0;
        $confirmedOut = 0;
        if ($user) {
            $pending = InvoiceDistribution::query()
                ->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
                ->where('status', 'draft')
                ->count();

            $confirmedOut = InvoiceDistribution::query()
                ->where('from_user_id', $user->id)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->count();
        }

        return [
            'skus' => $rows->count(),
            'units' => (int) $rows->sum('available_qty'),
            'points' => (int) $rows->sum('available_points'),
            'pending_drafts' => $pending,
            'confirmed_out' => $confirmedOut,
            'role_label' => $this->reportRole() === 'wholesale' ? 'مخزون الجملة' : 'مخزون القطاعي',
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getFilteredStockProperty(): Collection
    {
        $term = mb_strtolower(trim($this->search));

        return $this->rawStockRows()
            ->filter(function (array $row) use ($term) {
                $qty = (int) ($row['available_qty'] ?? 0);
                if ($this->stockFilter === 'in_stock' && $qty <= 0) {
                    return false;
                }
                if ($this->stockFilter === 'empty' && $qty > 0) {
                    return false;
                }
                if ($term === '') {
                    return true;
                }
                $hay = mb_strtolower(implode(' ', array_filter([
                    $row['name'] ?? '',
                    $row['category_name'] ?? '',
                    $row['tag'] ?? '',
                    $row['group_name'] ?? '',
                    (string) ($row['product_id'] ?? ''),
                ])));

                return str_contains($hay, $term);
            })
            ->values();
    }

    public function getSelectedProductProperty(): ?Product
    {
        if (! $this->selectedProductId) {
            return null;
        }

        return Product::query()
            ->with(['translations', 'images'])
            ->find($this->selectedProductId);
    }

    /**
     * Distribution ledger for the actor (optionally filtered by product).
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, InvoiceDistribution>
     */
    public function getHistoryProperty()
    {
        $user = $this->actor();
        if (! $user) {
            return InvoiceDistribution::query()->whereRaw('0 = 1')->paginate(12, pageName: 'historyPage');
        }

        $query = InvoiceDistribution::query()
            ->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
            ->with([
                'fromUser:id,name,brand_name',
                'toUser:id,name,brand_name',
                'invoice:id,number',
                'items.invoiceItem.product.translations',
            ])
            ->latest('id');

        if ($this->historyStatus !== 'all') {
            $query->where('status', $this->historyStatus);
        }

        if ($this->selectedProductId) {
            $pid = $this->selectedProductId;
            $query->whereHas('items.invoiceItem', fn ($q) => $q->where('product_id', $pid));
        }

        if (trim($this->search) !== '' && ! $this->selectedProductId) {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->whereHas('invoice', fn ($i) => $i->where('number', 'like', $term))
                    ->orWhereHas('fromUser', fn ($u) => $u->where('name', 'like', $term)->orWhere('brand_name', 'like', $term))
                    ->orWhereHas('toUser', fn ($u) => $u->where('name', 'like', $term)->orWhere('brand_name', 'like', $term))
                    ->orWhereHas('items.invoiceItem.product.translations', fn ($t) => $t->where('name', 'like', $term));
            });
        }

        return $query->paginate(12, pageName: 'historyPage');
    }

    /**
     * Qty of selected product inside a distribution.
     */
    public function productQtyInDistribution(InvoiceDistribution $dist): int
    {
        if (! $this->selectedProductId) {
            return (int) $dist->items->sum('quantity');
        }

        return (int) $dist->items
            ->filter(fn (InvoiceDistributionItem $i) => (int) ($i->invoiceItem?->product_id) === (int) $this->selectedProductId)
            ->sum('quantity');
    }

    public function productPointsInDistribution(InvoiceDistribution $dist): int
    {
        if (! $this->selectedProductId) {
            return (int) $dist->items->sum('points_value');
        }

        return (int) $dist->items
            ->filter(fn (InvoiceDistributionItem $i) => (int) ($i->invoiceItem?->product_id) === (int) $this->selectedProductId)
            ->sum('points_value');
    }

    public static function statusMeta(string $status): array
    {
        return match ($status) {
            'draft' => ['label' => 'بانتظار الاعتماد', 'tone' => 'amber'],
            'confirmed' => ['label' => 'معتمد', 'tone' => 'blue'],
            'points_awarded' => ['label' => 'نقاط ممنوحة', 'tone' => 'green'],
            default => ['label' => $status, 'tone' => 'gray'],
        };
    }

    public static function canAccessReport(): bool
    {
        $user = auth()->user();

        return (bool) ($user && $user->canStorePermission(StorePermissions::INVOICES_VIEW));
    }
}
