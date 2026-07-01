<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Concerns\HandlesNetworkPosCart;
use App\Filament\Resources\InvoiceResource;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\InvoiceNumberService;
use App\Services\NetworkInventoryService;
use App\Services\RetailDistributionPosService;
use App\Services\WholesalerNetworkService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

class CreateRetailPos extends Page
{
    use HandlesNetworkPosCart;

    protected static string $resource = InvoiceResource::class;

    protected static string $view = 'filament.resources.invoice-resource.pages.network-pos';

    protected static ?string $title = 'بيع للتاجر القطاعي';

    public string $posMode = 'retail';

    public ?int $retailTraderId = null;

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->isWholesaleDistributor() ?? false;
    }

    public function mount(): void
    {
        app(WholesalerNetworkService::class)->getSummary(auth()->user());
    }

    public function getNextInvoiceNumberProperty(): string
    {
        return app(InvoiceNumberService::class)->previewNext('wholesale_out');
    }

    public function getWalletBalanceProperty(): int
    {
        return app(NetworkInventoryService::class)->walletBalance(auth()->user());
    }

    public function getSummaryProperty(): array
    {
        return app(WholesalerNetworkService::class)->getSummary(auth()->user());
    }

    public function getRetailTradersProperty(): Collection
    {
        return User::query()
            ->where('role', 'retail_trader')
            ->where('parent_distributor_id', auth()->id())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
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
        return app(NetworkInventoryService::class)->stockForWholesaler(auth()->user());
    }

    public function issueInvoice(): void
    {
        if (! $this->retailTraderId) {
            Notification::make()->danger()->title('اختر تاجر القطاعي')->send();

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
            $retailTrader = User::findOrFail($this->retailTraderId);

            $invoices = app(RetailDistributionPosService::class)->issueToRetailTrader(
                wholesaler: auth()->user(),
                retailTrader: $retailTrader,
                lines: $this->cartLines(),
                issuedBy: auth()->user(),
            );

            $count = count($invoices);
            $points = $this->cartPoints;

            Notification::make()
                ->success()
                ->title('تم إصدار فاتورة الصادر')
                ->body("{$count} فاتورة صادرة — {$points} نقطة مخصومة من رصيدك")
                ->send();

            $this->redirect(InvoiceResource::getUrl('index', ['activeTab' => 'outgoing']));
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
        }
    }
}
