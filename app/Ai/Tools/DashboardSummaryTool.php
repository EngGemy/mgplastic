<?php

namespace App\Ai\Tools;

use App\Services\MgDashboardStatsService;

class DashboardSummaryTool extends AbstractTool
{
    public function name(): string
    {
        return 'dashboard_summary';
    }

    public function description(): string
    {
        return 'Get MG Plastic admin dashboard summary cards (users, invoices, points, products).';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'panel' => [
                'type' => 'string',
                'enum' => ['admin', 'distributor', 'trader'],
                'description' => 'Which panel stats to load (default admin)',
            ],
            'user_id' => [
                'type' => 'integer',
                'description' => 'Optional user id for distributor/trader scoped stats',
            ],
        ]);
    }

    public function handle(array $arguments): array
    {
        $panel = (string) ($arguments['panel'] ?? 'admin');
        $user = null;
        if (! empty($arguments['user_id'])) {
            $user = \App\Models\User::query()->find((int) $arguments['user_id']);
        }

        $stats = app(MgDashboardStatsService::class)->forPanel($panel, $user);

        return [
            'panel' => $panel,
            'summary' => $stats,
        ];
    }
}
