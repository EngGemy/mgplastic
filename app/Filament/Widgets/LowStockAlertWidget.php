<?php

namespace App\Filament\Widgets;

use App\Services\LowStockAlertService;
use Filament\Widgets\Widget;

class LowStockAlertWidget extends Widget
{
    protected static string $view = 'filament.widgets.low-stock-alert';

    protected static bool $isDiscovered = false;

    protected static ?int $sort = -200;

    protected int|string|array $columnSpan = 'full';

    /** @var list<array{product_id:int, name:string, available_qty:int, image:?string}> */
    public array $items = [];

    public bool $showPopup = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isWholesaleDistributor()
            || $user->isRetailTrader()
            || $user->isCentralWarehouse()
            || in_array($user->role, ['super_admin', 'admin'], true);
    }

    public function mount(): void
    {
        $user = auth()->user();

        // Admin sees nothing network-scoped unless they also hold warehouse stock.
        if ($user && in_array($user->role, ['super_admin', 'admin'], true)
            && ! $user->isWholesaleDistributor()
            && ! $user->isCentralWarehouse()) {
            $this->items = [];
            $this->showPopup = false;

            return;
        }

        $this->items = app(LowStockAlertService::class)->forUser($user)->all();
        $dismissed = (bool) session('low_stock_alert_dismissed_at');
        $this->showPopup = count($this->items) > 0 && ! $dismissed;
    }

    public function dismissPopup(): void
    {
        session(['low_stock_alert_dismissed_at' => now()->toIso8601String()]);
        $this->showPopup = false;
    }

    public function getViewData(): array
    {
        return [
            'items' => $this->items,
            'showPopup' => $this->showPopup,
            'threshold' => LowStockAlertService::THRESHOLD,
            'count' => count($this->items),
        ];
    }
}
