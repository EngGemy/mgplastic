<?php

namespace App\Filament\Warehouse\Widgets;

use App\Services\MobileDashboardService;
use Filament\Widgets\Widget;

class WarehousePointsPanelWidget extends Widget
{
    protected static string $view = 'filament.warehouse.widgets.points-panel';

    protected static bool $isDiscovered = false;

    protected static ?int $sort = -300;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && $user->isCentralWarehouse();
    }

    public function getViewData(): array
    {
        $user = auth()->user();

        return [
            'panel' => MobileDashboardService::pointsPanelForWholesale($user),
            'storeName' => $user?->brand_name ?: $user?->name,
            'networkCode' => $user?->network_code,
        ];
    }
}
