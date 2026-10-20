<?php

namespace App\Services\Reputation;

use App\Models\InvoiceDistribution;
use App\Models\PlumberStreak;
use App\Models\User;
use App\Services\WhatsAppNotifier;
use App\Support\DeepLink;
use App\Support\WhatsAppMessage;
use Carbon\Carbon;

class StreakService
{
    public function __construct(
        protected BadgeService $badges,
        protected WhatsAppNotifier $whatsapp,
    ) {}

    public function markActive(User $plumber): PlumberStreak
    {
        $tz = (string) config('reputation.timezone', 'Africa/Tripoli');
        $weekStart = Carbon::now($tz)->startOfWeek(Carbon::SATURDAY)->toDateString();
        $streak = PlumberStreak::query()->firstOrCreate(
            ['user_id' => $plumber->id],
            ['freezes_available' => 1, 'last_freeze_reset' => now()->startOfMonth()->toDateString()]
        );

        if ($streak->last_active_week?->toDateString() === $weekStart) {
            return $streak;
        }

        $prevWeek = Carbon::now($tz)->startOfWeek(Carbon::SATURDAY)->subWeek()->toDateString();
        if ($streak->last_active_week?->toDateString() === $prevWeek || $streak->current_weeks === 0) {
            $streak->current_weeks = (int) $streak->current_weeks + 1;
        } else {
            $streak->current_weeks = 1;
        }
        $streak->best_weeks = max((int) $streak->best_weeks, (int) $streak->current_weeks);
        $streak->last_active_week = $weekStart;
        $streak->save();

        $this->badges->evaluate($plumber, 'streak');

        return $streak;
    }

    public function processWeekly(): void
    {
        $tz = (string) config('reputation.timezone', 'Africa/Tripoli');
        $lastWeek = Carbon::now($tz)->startOfWeek(Carbon::SATURDAY)->subWeek()->toDateString();

        PlumberStreak::query()->chunkById(100, function ($rows) use ($lastWeek) {
            foreach ($rows as $streak) {
                if ($streak->last_active_week?->toDateString() === $lastWeek) {
                    continue;
                }
                // inactive last week
                if ((int) $streak->freezes_available > 0 && (int) $streak->current_weeks > 0) {
                    $streak->freezes_available = (int) $streak->freezes_available - 1;
                    $streak->last_active_week = $lastWeek; // freeze preserves week
                    $streak->save();
                } else {
                    $streak->best_weeks = max((int) $streak->best_weeks, (int) $streak->current_weeks);
                    $streak->current_weeks = 0;
                    $streak->save();
                }
            }
        });

        // monthly freeze reset
        if (now($tz)->day === 1) {
            PlumberStreak::query()->update([
                'freezes_available' => 1,
                'last_freeze_reset' => now($tz)->toDateString(),
            ]);
        }
    }

    public function notifyAtRisk(): void
    {
        $tz = (string) config('reputation.timezone', 'Africa/Tripoli');
        $weekStart = Carbon::now($tz)->startOfWeek(Carbon::SATURDAY);

        PlumberStreak::query()
            ->where('current_weeks', '>=', 2)
            ->with('user')
            ->chunkById(50, function ($rows) use ($weekStart) {
                foreach ($rows as $streak) {
                    $user = $streak->user;
                    if (! $user || $user->role !== 'plumber') {
                        continue;
                    }
                    $active = InvoiceDistribution::query()
                        ->where('tier', 3)
                        ->where('to_user_id', $user->id)
                        ->where('status', 'confirmed')
                        ->where('confirmed_at', '>=', $weekStart)
                        ->exists();
                    if ($active) {
                        continue;
                    }
                    $tpl = config('reminder_templates.streak_risk.0');
                    $text = str_replace('{weeks}', (string) $streak->current_weeks, (string) $tpl);
                    $msg = WhatsAppMessage::make()
                        ->header('🔥')
                        ->greet($user->name)
                        ->event('🔥', 'سلسلتك معرّضة للانتهاء')
                        ->line($text)
                        ->openInApp(DeepLink::for('home', 0))
                        ->footer()
                        ->toString();
                    $this->whatsapp->sendMany([[
                        'phone' => $user->phone,
                        'text' => $msg,
                        'event' => 'streak_risk',
                        'recipient_type' => 'customer',
                        'reference_type' => 'user',
                        'reference_id' => $user->id,
                    ]]);
                }
            });
    }

    public function payload(User $plumber): array
    {
        $streak = PlumberStreak::query()->firstOrCreate(
            ['user_id' => $plumber->id],
            ['freezes_available' => 1]
        );
        $tz = (string) config('reputation.timezone', 'Africa/Tripoli');
        $weekStart = Carbon::now($tz)->startOfWeek(Carbon::SATURDAY);
        $weekActive = InvoiceDistribution::query()
            ->where('tier', 3)
            ->where('to_user_id', $plumber->id)
            ->where('status', 'confirmed')
            ->where('confirmed_at', '>=', $weekStart)
            ->exists();

        return [
            'current_weeks' => (int) $streak->current_weeks,
            'best_weeks' => (int) $streak->best_weeks,
            'freezes_available' => (int) $streak->freezes_available,
            'week_active' => $weekActive,
            'ends_at' => $weekStart->copy()->addDays(6)->endOfDay()->toIso8601String(),
        ];
    }
}
