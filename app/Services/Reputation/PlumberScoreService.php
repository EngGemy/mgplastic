<?php

namespace App\Services\Reputation;

use App\Models\InvoiceDistribution;
use App\Models\InvoiceReturn;
use App\Models\PlumberScore;
use App\Models\PlumberScoreEvent;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Support\InvoiceReturnStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PlumberScoreService
{
    /**
     * @return array{score:int,level:string,delta:int,level_changed:bool,components:array}
     */
    public function compute(User $plumber): array
    {
        if (! config('reputation.enabled', true) || $plumber->role !== 'plumber') {
            return ['score' => 0, 'level' => 'bronze', 'delta' => 0, 'level_changed' => false, 'components' => []];
        }

        $previous = PlumberScore::query()->where('user_id', $plumber->id)->first();
        $prevScore = (int) ($previous?->score ?? 0);
        $prevLevel = (string) ($previous?->level ?? 'bronze');

        $components = [
            'volume' => $this->scoreVolume($plumber),
            'consistency' => $this->scoreConsistency($plumber),
            'returns' => $this->scoreReturns($plumber),
            'warranty' => $this->scoreWarranty($plumber),
            'tenure' => $this->scoreTenure($plumber),
        ];

        $weights = (array) config('reputation.weights', []);
        $total = 0;
        foreach ($components as $key => $value) {
            $w = (int) ($weights[$key] ?? 0);
            $total += ($value / 100) * $w;
        }
        $score = (int) max(0, min(100, round($total)));
        $level = $this->levelFor($score);
        $confirmed = $this->confirmedDistributionsQuery($plumber)->count();

        PlumberScore::query()->updateOrCreate(
            ['user_id' => $plumber->id],
            [
                'score' => $score,
                'level' => $level,
                'components' => $components,
                'confirmed_distributions_count' => $confirmed,
            ]
        );

        $delta = $score - $prevScore;
        if ($delta !== 0 || $level !== $prevLevel) {
            PlumberScoreEvent::query()->create([
                'user_id' => $plumber->id,
                'type' => 'recompute',
                'points_delta' => $delta,
                'meta' => [
                    'from_score' => $prevScore,
                    'to_score' => $score,
                    'from_level' => $prevLevel,
                    'to_level' => $level,
                    'components' => $components,
                ],
                'created_at' => now(),
            ]);
        }

        return [
            'score' => $score,
            'level' => $level,
            'delta' => $delta,
            'level_changed' => $level !== $prevLevel,
            'components' => $components,
        ];
    }

    public function levelFor(int $score): string
    {
        foreach ((array) config('reputation.levels', []) as $key => $range) {
            if ($score >= (int) $range['min'] && $score <= (int) $range['max']) {
                return $key;
            }
        }

        return 'bronze';
    }

    public function levelLabel(string $level): string
    {
        return (string) (config("reputation.levels.{$level}.label") ?? $level);
    }

    public function nextLevel(int $score): ?array
    {
        $levels = (array) config('reputation.levels', []);
        $order = array_keys($levels);
        $current = $this->levelFor($score);
        $idx = array_search($current, $order, true);
        if ($idx === false || $idx >= count($order) - 1) {
            return null;
        }
        $next = $order[$idx + 1];
        $needed = max(0, (int) $levels[$next]['min'] - $score);

        return ['name' => $next, 'label' => $this->levelLabel($next), 'points_needed' => $needed];
    }

    public function confirmedDistributionsQuery(User $plumber)
    {
        // Fully returned distributions are excluded via left join on settled returns for same invoice+buyer.
        $returnedInvoiceIds = InvoiceReturn::query()
            ->where('from_user_id', $plumber->id)
            ->whereIn('status', InvoiceReturnStatus::settled())
            ->pluck('invoice_id')
            ->filter()
            ->all();

        return InvoiceDistribution::query()
            ->where('tier', 3)
            ->where('to_user_id', $plumber->id)
            ->where('status', 'confirmed')
            ->when($returnedInvoiceIds !== [], fn ($q) => $q->whereNotIn('invoice_id', $returnedInvoiceIds));
    }

    protected function scoreVolume(User $plumber): int
    {
        $days = (int) config('reputation.volume_lookback_days', 90);

        $peers = InvoiceDistribution::query()
            ->where('tier', 3)
            ->where('status', 'confirmed')
            ->where('confirmed_at', '>=', now()->subDays($days))
            ->select('to_user_id', DB::raw('COUNT(*) as c'))
            ->groupBy('to_user_id')
            ->pluck('c');

        $myCount = $this->confirmedDistributionsQuery($plumber)
            ->where('confirmed_at', '>=', now()->subDays($days))
            ->count();

        if ($peers->isEmpty()) {
            return $myCount > 0 ? 50 : 0;
        }
        $max = max(1, (int) $peers->max());

        return (int) min(100, round(($myCount / $max) * 100));
    }

    protected function scoreConsistency(User $plumber): int
    {
        $weeks = (int) config('reputation.consistency_weeks', 12);
        $tz = (string) config('reputation.timezone', 'Africa/Tripoli');
        $active = 0;
        for ($i = 0; $i < $weeks; $i++) {
            $start = Carbon::now($tz)->startOfWeek(Carbon::SATURDAY)->subWeeks($i);
            $end = (clone $start)->addDays(6)->endOfDay();
            $has = InvoiceDistribution::query()
                ->where('tier', 3)
                ->where('to_user_id', $plumber->id)
                ->where('status', 'confirmed')
                ->whereBetween('confirmed_at', [$start, $end])
                ->exists();
            if ($has) {
                $active++;
            }
        }

        return (int) round(($active / max(1, $weeks)) * 100);
    }

    protected function scoreReturns(User $plumber): int
    {
        $sales = $this->confirmedDistributionsQuery($plumber)->count();
        if ($sales === 0) {
            return 80;
        }
        $returns = InvoiceReturn::query()
            ->where('from_user_id', $plumber->id)
            ->whereIn('status', InvoiceReturnStatus::settled())
            ->count();
        $rate = $returns / max(1, $sales);

        return (int) max(0, min(100, round((1 - min(1, $rate * 2)) * 100)));
    }

    protected function scoreWarranty(User $plumber): int
    {
        $issued = Warranty::query()->where('plumber_id', $plumber->id)->where('status', 'active')->count();
        $badClaims = WarrantyClaim::query()
            ->whereHas('warranty', fn ($q) => $q->where('plumber_id', $plumber->id))
            ->where('status', 'resolved')
            ->count();
        if ($issued === 0) {
            return 40;
        }
        $penalty = min(60, $badClaims * 15);

        return (int) max(20, min(100, 60 + min(40, $issued * 5) - $penalty));
    }

    protected function scoreTenure(User $plumber): int
    {
        $months = max(0, $plumber->created_at?->diffInMonths(now()) ?? 0);

        return (int) min(100, $months * 8);
    }
}
