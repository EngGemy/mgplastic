<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Concerns\HandlesNetworkPosCart;
use App\Filament\Resources\InvoiceDistributionResource;
use App\Filament\Resources\InvoiceResource;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\PlumberDistributionPosService;
use App\Services\RetailTraderNetworkService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

class CreatePlumberPos extends Page
{
    use HandlesNetworkPosCart;

    protected static string $resource = InvoiceResource::class;

    protected static string $view = 'filament.resources.invoice-resource.pages.network-pos';

    protected static ?string $title = 'بيع للسباك — منح النقاط';

    public string $posMode = 'plumber';

    protected static bool $shouldRegisterNavigation = false;

    public ?int $plumberId = null;

    public string $plumberSearch = '';

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->isRetailTrader() ?? false;
    }

    public function mount(): void
    {
        app(RetailTraderNetworkService::class)->getSummary(auth()->user());
    }

    public function getNextInvoiceNumberProperty(): string
    {
        return 'توزيع نقاط — '.now()->format('Y/m/d H:i');
    }

    public function getWalletBalanceProperty(): int
    {
        return app(NetworkInventoryService::class)->walletBalance(auth()->user());
    }

    public function getSummaryProperty(): array
    {
        return app(RetailTraderNetworkService::class)->getSummary(auth()->user());
    }

    public function getPlumbersProperty(): Collection
    {
        $query = User::query()
            ->where('role', User::ROLE_PLUMBER)
            ->where('is_active', true)
            ->orderBy('name');

        $term = trim($this->plumberSearch);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('network_code', 'like', $like);
            });
        }

        return $query->get(['id', 'name', 'phone', 'network_code']);
    }

    public function getSelectedPlumberProperty(): ?User
    {
        if (! $this->plumberId) {
            return null;
        }

        return User::query()
            ->where('role', User::ROLE_PLUMBER)
            ->whereKey($this->plumberId)
            ->first(['id', 'name', 'phone', 'network_code']);
    }

    public function selectPlumber(int $id): void
    {
        $exists = User::query()
            ->where('role', User::ROLE_PLUMBER)
            ->where('is_active', true)
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            Notification::make()->danger()->title('سباك غير صحيح')->send();

            return;
        }

        $this->plumberId = $id;
        $this->plumberSearch = '';
    }

    public function clearPlumber(): void
    {
        $this->plumberId = null;
        $this->plumberSearch = '';
    }

    public function getCategoriesProperty(): Collection
    {
        $ids = $this->stockRows()->pluck('category_id')->filter()->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return ProductCategory::query()
            ->with('translations')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
    }

    protected function stockRows(): Collection
    {
        return app(NetworkInventoryService::class)->stockForRetailTrader(auth()->user());
    }

    public function issueInvoice(): void
    {
        if (! $this->plumberId) {
            Notification::make()->danger()->title('اختر السباك')->send();

            return;
        }

        if (empty($this->cart)) {
            Notification::make()->danger()->title('السلة فارغة')->send();

            return;
        }

        if ($this->cartPoints > $this->walletBalance) {
            Notification::make()
                ->danger()
                ->title('رصيد النقاط غير كافٍ')
                ->body("المطلوب {$this->cartPoints} نقطة — رصيدك {$this->walletBalance} نقطة")
                ->send();

            return;
        }

        try {
            $plumber = User::findOrFail($this->plumberId);

            $distributions = app(PlumberDistributionPosService::class)->issueToPlumber(
                retailTrader: auth()->user(),
                plumber: $plumber,
                lines: $this->cartLines(),
                issuedBy: auth()->user(),
            );

            $points = $this->cartPoints;

            Notification::make()
                ->success()
                ->title('تم توزيع النقاط للسباك')
                ->body(count($distributions).' توزيع — '.$points.' نقطة')
                ->send();

            $this->redirect(InvoiceDistributionResource::getUrl('index'));
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
        }
    }
}
