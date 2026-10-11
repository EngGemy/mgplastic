<?php

namespace App\Ai\Tools;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReportUsersTool extends AbstractTool
{
    public function name(): string
    {
        return 'report_users';
    }

    public function description(): string
    {
        return 'Users report: counts by role, approval, and recent signups.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'days' => [
                'type' => 'integer',
                'description' => 'Recent window in days for new signups (default 30)',
            ],
        ]);
    }

    public function handle(array $arguments): array
    {
        $days = max(1, min(365, (int) ($arguments['days'] ?? 30)));

        $byRole = User::query()
            ->select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role')
            ->all();

        $pending = User::query()->where('is_approved', false)->count();
        $inactive = User::query()->where('is_active', false)->count();
        $recent = User::query()->where('created_at', '>=', now()->subDays($days))->count();

        return [
            'totals' => [
                'all' => User::query()->count(),
                'pending_approval' => $pending,
                'inactive' => $inactive,
                'created_last_days' => $recent,
                'days' => $days,
            ],
            'by_role' => $byRole,
        ];
    }
}
