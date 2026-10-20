<?php

namespace App\Services\Reminders;

use App\Jobs\Reminders\SendReminderJob;
use App\Models\ReminderLog;
use App\Models\ReminderPreference;
use App\Models\User;
use App\Services\Ai\GeminiMessageService;
use App\Services\WhatsAppBrandCard;
use App\Support\DeepLink;
use App\Support\Money;
use App\Support\WhatsAppMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ReminderService
{
    public function __construct(
        protected GeminiMessageService $ai,
        protected WhatsAppBrandCard $cards,
    ) {}

    public function dispatchWeekly(): void
    {
        if (! config('reminders.enabled') && ! config('reminders.admin_only')) {
            return;
        }
        $tz = (string) config('reminders.timezone', 'Africa/Tripoli');
        $roles = ['retail_trader', 'wholesale_distributor'];
        $query = User::query()->whereIn('role', $roles)->where('is_active', true);
        if (config('reminders.admin_only')) {
            // preview: only users whose phone is in admin list — still iterate owners but ReminderJob checks
        }

        $delay = 0;
        $spread = (int) config('reminders.spread_minutes', 75) * 60;
        $perHour = (int) config('reminders.per_hour', 40);
        $slot = max(1, (int) floor(3600 / max(1, $perHour)));

        $query->orderBy('id')->chunkById(50, function ($users) use (&$delay, $slot, $spread, $tz) {
            foreach ($users as $user) {
                if ($delay > $spread) {
                    $delay = random_int(0, 60);
                }
                SendReminderJob::dispatch($user->id, 'weekly_owner')
                    ->delay(now()->addSeconds($delay + random_int(0, 30)));
                $delay += $slot;
            }
        });
    }

    public function dispatchDailyPlumber(): void
    {
        if (! config('reminders.enabled') && ! config('reminders.admin_only')) {
            return;
        }
        $delay = 0;
        $slot = max(1, (int) floor(3600 / max(1, (int) config('reminders.per_hour', 40))));
        User::query()->where('role', 'plumber')->where('is_active', true)
            ->orderBy('id')->chunkById(50, function ($users) use (&$delay, $slot) {
                foreach ($users as $user) {
                    SendReminderJob::dispatch($user->id, 'daily_plumber')
                        ->delay(now()->addSeconds($delay + random_int(0, 20)));
                    $delay += $slot;
                }
            });
    }

    public function sendFor(User $user, string $kind): string
    {
        $pref = ReminderPreference::forUser($user);
        if ($kind === 'weekly_owner' && ! $pref->weekly_enabled) {
            return $this->log($user, $kind, 'skipped', false);
        }
        if ($kind === 'daily_plumber' && ! $pref->daily_points_enabled) {
            return $this->log($user, $kind, 'skipped', false);
        }
        if ($this->inQuietHours($pref)) {
            return $this->log($user, $kind, 'skipped', false);
        }
        if (! $this->withinReminderDailyLimit()) {
            return $this->log($user, $kind, 'skipped', false);
        }

        $facts = $kind === 'weekly_owner'
            ? $this->weeklyFacts($user)
            : $this->dailyFacts($user);

        if ($kind === 'daily_plumber' && empty($facts['should_send'])) {
            return $this->log($user, $kind, 'skipped', false);
        }

        $aiUsed = false;
        $body = $this->ai->compose($kind, $facts);
        if ($body && ! str_contains($body, 'افتح تطبيق MG لمتابعة')) {
            // heuristic: template fallback often contains that — still fine
        }
        // Track AI usage via cache flag set inside compose budget path is opaque; mark true if key present
        $aiUsed = filled(config('services.gemini.key'));

        $theme = $kind === 'weekly_owner' ? 'reminder_weekly' : 'reminder_daily';
        $asset = $this->cards->assetFor($theme, [
            'title' => $kind === 'weekly_owner' ? 'ملخص أسبوعي' : 'تحديث نقاطك',
            'hero' => (string) ($facts['points'] ?? $facts['balance'] ?? 'MG'),
            'hero_label' => $kind === 'weekly_owner' ? 'نقطة هذا الأسبوع' : 'رصيدك',
            'client' => $user->name,
        ]);

        $deep = DeepLink::for($kind === 'daily_plumber' ? 'points' : 'home', 0);
        $text = WhatsAppMessage::make()
            ->header($kind === 'weekly_owner' ? '📊' : '⭐')
            ->greet($user->name)
            ->event($kind === 'weekly_owner' ? '📊' : '⭐', $kind === 'weekly_owner' ? 'ملخص أسبوعي' : 'تحديث يومي')
            ->line($body ?: '')
            ->openInApp($deep)
            ->footer()
            ->toString();

        $phones = config('reminders.admin_only')
            ? app(\App\Services\WhatsAppNotifier::class)->adminPhones()
            : array_filter([$user->phone]);

        $messages = [];
        foreach ($phones as $phone) {
            $messages[] = [
                'phone' => is_string($phone) ? $phone : (string) $phone,
                'text' => $text,
                'image' => $asset,
                'event' => $kind === 'weekly_owner' ? 'reminder_weekly' : 'reminder_daily',
                'recipient_type' => config('reminders.admin_only') ? 'admin' : 'customer',
                'reference_type' => 'user',
                'reference_id' => $user->id,
            ];
        }
        app(\App\Services\WhatsAppNotifier::class)->sendMany($messages);

        if ($kind === 'weekly_owner') {
            $pref->update(['last_weekly_sent_at' => now()]);
        } else {
            $pref->update(['last_daily_sent_at' => now()]);
        }
        Cache::increment('reminders:daily:'.now()->toDateString());

        return $this->log($user, $kind, 'sent', $aiUsed);
    }

    protected function weeklyFacts(User $user): array
    {
        $tz = (string) config('reminders.timezone', 'Africa/Tripoli');
        $from = \Carbon\Carbon::now($tz)->startOfWeek(\Carbon\Carbon::SATURDAY);
        $orders = 0;
        $points = 0;
        $pendingReturns = 0;
        $topName = '—';

        try {
            if ($user->role === 'retail_trader') {
                $dists = \App\Models\InvoiceDistribution::query()
                    ->where('tier', 3)->where('from_user_id', $user->id)
                    ->where('status', 'confirmed')->where('confirmed_at', '>=', $from);
                $orders = (clone $dists)->count();
                $points = (int) \App\Models\InvoiceDistributionItem::query()
                    ->whereIn('distribution_id', (clone $dists)->select('id'))
                    ->sum('points_value');
                $pendingReturns = \App\Models\InvoiceReturn::query()
                    ->where('to_user_id', $user->id)->where('status', 'pending')->count();
                $topId = \App\Models\InvoiceDistribution::query()
                    ->where('tier', 3)->where('from_user_id', $user->id)
                    ->where('status', 'confirmed')->where('confirmed_at', '>=', $from)
                    ->selectRaw('to_user_id, count(*) as c')->groupBy('to_user_id')->orderByDesc('c')->value('to_user_id');
                if ($topId) {
                    $topName = explode(' ', (string) User::query()->whereKey($topId)->value('name'))[0] ?: '—';
                }
            } elseif ($user->role === 'wholesale_distributor') {
                $dists = \App\Models\InvoiceDistribution::query()
                    ->where('tier', 2)->where('from_user_id', $user->id)
                    ->where('status', 'confirmed')->where('confirmed_at', '>=', $from);
                $orders = (clone $dists)->count();
                $points = (int) \App\Models\InvoiceDistributionItem::query()
                    ->whereIn('distribution_id', (clone $dists)->select('id'))
                    ->sum('points_value');
                $pendingReturns = \App\Models\InvoiceReturn::query()
                    ->where('to_user_id', $user->id)->where('status', 'pending')->count();
            }
        } catch (\Throwable) {
        }

        return [
            'orders' => $orders,
            'points' => $points,
            'stock_hint' => '',
            'inactive_hint' => '',
            'returns_hint' => $pendingReturns > 0 ? "مرتجعات بانتظارك: *{$pendingReturns}*. " : '',
            'top_plumber' => $topName,
        ];
    }

    protected function dailyFacts(User $user): array
    {
        $balance = 0;
        try {
            $balance = (int) $user->walletAccounts()->sum('balance_points');
        } catch (\Throwable) {
        }
        $earned = 0;
        try {
            $earned = (int) \App\Models\WalletTransaction::query()
                ->whereHas('wallet', fn ($q) => $q->where('owner_id', $user->id))
                ->where('created_at', '>=', now()->startOfDay())
                ->where('points_delta', '>', 0)
                ->sum('points_delta');
        } catch (\Throwable) {
        }
        $streak = \App\Models\PlumberStreak::query()->where('user_id', $user->id)->first();
        $tz = (string) config('reminders.timezone', 'Africa/Tripoli');
        $weekStart = \Carbon\Carbon::now($tz)->startOfWeek(\Carbon\Carbon::SATURDAY);
        $weekActive = \App\Models\InvoiceDistribution::query()
            ->where('tier', 3)->where('to_user_id', $user->id)->where('status', 'confirmed')
            ->where('confirmed_at', '>=', $weekStart)->exists();
        $streakAtRisk = ((int) ($streak?->current_weeks ?? 0) >= 2) && ! $weekActive;
        $should = $earned > 0 || $streakAtRisk;

        return [
            'balance' => Money::points($balance),
            'earned' => $earned,
            'streak_hint' => $streakAtRisk
                ? 'سلسلتك *'.$streak->current_weeks.'* أسبوع معرّضة للانتهاء.'
                : '',
            'next_hint' => '',
            'should_send' => $should || (config('reminders.admin_only') && config('reminders.enabled') === false),
            'points' => $balance,
        ];
    }

    protected function inQuietHours(ReminderPreference $pref): bool
    {
        $qh = (array) ($pref->quiet_hours ?? []);
        if ($qh === []) {
            return false;
        }
        $hour = (int) now((string) config('reminders.timezone', 'Africa/Tripoli'))->format('G');
        $start = (int) ($qh['start'] ?? 23);
        $end = (int) ($qh['end'] ?? 7);
        if ($start > $end) {
            return $hour >= $start || $hour < $end;
        }

        return $hour >= $start && $hour < $end;
    }

    protected function withinReminderDailyLimit(): bool
    {
        $limit = (int) config('reminders.daily_limit', 120);
        $used = (int) Cache::get('reminders:daily:'.now()->toDateString(), 0);

        return $used < $limit;
    }

    protected function log(User $user, string $kind, string $status, bool $aiUsed): string
    {
        ReminderLog::query()->create([
            'user_id' => $user->id,
            'kind' => $kind,
            'status' => $status,
            'channel' => 'whatsapp',
            'ai_used' => $aiUsed,
            'created_at' => now(),
        ]);

        return $status;
    }
}
