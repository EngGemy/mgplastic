<?php

namespace App\Filament\Warehouse\Widgets;

use App\Filament\Distributor\Pages\DistributorPos;
use App\Filament\Distributor\Resources\DistributorDistributionResource;
use App\Filament\Distributor\Resources\DistributorInvoiceResource;
use App\Filament\Distributor\Resources\DistributorMyStoreResource;
use App\Filament\Distributor\Resources\DistributorOrderResource;
use App\Filament\Distributor\Resources\DistributorRetailTraderResource;
use Filament\Widgets\Widget;

class WarehouseQuickLinksWidget extends Widget
{
    protected static string $view = 'filament.warehouse.widgets.quick-links';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = -100;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isCentralWarehouse();
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $links = [];

        $candidates = [
            ['الطلبيات', 'استقبال وتأكيد / شحن', DistributorOrderResource::class, 'index'],
            ['الفواتير', 'وارد وصادر المخزن', DistributorInvoiceResource::class, 'index'],
            ['التوزيعات', 'سلسلة النقاط', DistributorDistributionResource::class, 'index'],
            ['صرف للقطاعي', 'بيع من المخزون', DistributorPos::class, null],
            ['تجار القطاعي', 'شبكة المخزن', DistributorRetailTraderResource::class, 'index'],
            ['متجري', 'واجهة الكتالوج MG', DistributorMyStoreResource::class, 'index'],
        ];

        foreach ($candidates as [$label, $sub, $class, $page]) {
            try {
                $url = $page === null
                    ? $class::getUrl(panel: 'warehouse')
                    : $class::getUrl($page, panel: 'warehouse');
                $links[] = compact('label', 'sub', 'url');
            } catch (\Throwable) {
                // Skip links that are not registered on this panel.
            }
        }

        return [
            'title' => 'اختصارات مخزن الدعوة',
            'links' => $links,
        ];
    }
}
