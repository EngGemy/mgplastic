<?php

namespace App\Ai\Tools;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportNetworkTool extends AbstractTool
{
    public function name(): string
    {
        return 'report_network';
    }

    public function description(): string
    {
        return 'Network report: wholesale distributors, retail traders, plumbers, and link counts.';
    }

    public function inputSchema(): array
    {
        return $this->schema();
    }

    public function handle(array $arguments): array
    {
        $wholesalers = User::query()->where('role', 'wholesale_distributor')->count();
        $retail = User::query()->where('role', 'retail_trader')->count();
        $plumbers = User::query()->where('role', 'plumber')->count();
        $approvedRetail = User::query()->where('role', 'retail_trader')->where('is_approved', true)->count();
        $independentRetail = User::query()->where('role', 'retail_trader')->where('is_independent', true)->count();

        $pivotLinks = 0;
        if (Schema::hasTable('wholesaler_retail_trader')) {
            $pivotLinks = (int) DB::table('wholesaler_retail_trader')->count();
        }

        $parentLinks = User::query()
            ->whereNotNull('parent_distributor_id')
            ->whereIn('role', ['retail_trader', 'plumber'])
            ->count();

        return [
            'wholesale_distributors' => $wholesalers,
            'retail_traders' => $retail,
            'retail_traders_approved' => $approvedRetail,
            'retail_traders_independent' => $independentRetail,
            'plumbers' => $plumbers,
            'wholesaler_retail_pivot_links' => $pivotLinks,
            'parent_distributor_links' => $parentLinks,
        ];
    }
}
