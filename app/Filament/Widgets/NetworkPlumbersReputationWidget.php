<?php

namespace App\Filament\Widgets;

use App\Models\PlumberScore;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Shared widget used on distributor + trader panels when registered there.
 * On admin panel shows global top plumbers.
 */
class NetworkPlumbersReputationWidget extends Widget
{
    protected static string $view = 'filament.widgets.network-plumbers-reputation';

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $role = auth()->user()?->role;

        return in_array($role, ['super_admin', 'admin', 'monitor', 'wholesale_distributor', 'retail_trader'], true);
    }

    public function getViewData(): array
    {
        $user = auth()->user();
        $query = PlumberScore::query()->with('user')->orderByDesc('score')->limit(10);

        if ($user?->role === 'retail_trader') {
            $ids = DB::table('retail_trader_plumber')->where('retail_trader_id', $user->id)->pluck('plumber_id');
            $query->whereIn('user_id', $ids);
        } elseif ($user?->role === 'wholesale_distributor') {
            $retailIds = DB::table('wholesaler_retail_trader')->where('wholesaler_id', $user->id)->pluck('retail_trader_id');
            $ids = DB::table('retail_trader_plumber')->whereIn('retail_trader_id', $retailIds)->pluck('plumber_id');
            $query->whereIn('user_id', $ids);
        }

        $rows = $query->get();

        return [
            'rows' => $rows,
            'weekTop' => $rows->first(),
        ];
    }
}
