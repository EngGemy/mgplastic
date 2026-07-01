<?php

namespace App\Filament\Widgets;

use App\Services\MgDashboardStatsService;
use Filament\Widgets\Widget;

class MgCrmDashboardWidget extends Widget
{
    protected static string $view = 'filament.widgets.crm-dashboard';

    protected static bool $isDiscovered = false;

    protected static ?int $sort = -200;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->check();
    }

    public function getViewData(): array
    {
        $panelId = filament()->getCurrentPanel()?->getId() ?? 'admin';

        return app(MgDashboardStatsService::class)->forPanel($panelId, auth()->user());
    }
}
