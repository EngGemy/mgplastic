<?php

namespace App\Services\Reputation;

use App\Models\Badge;
use App\Models\InvoiceDistribution;
use App\Models\PlumberScore;
use App\Models\PlumberStreak;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\Warranty;
use App\Services\WhatsAppBrandCard;
use App\Services\WhatsAppNotifier;
use App\Support\DeepLink;
use App\Support\WhatsAppMessage;
use Illuminate\Support\Facades\Event;

class BadgeService
{
    public function __construct(
        protected WhatsAppNotifier $whatsapp,
        protected WhatsAppBrandCard $cards,
    ) {}

    /**
     * @return list<array<string, mixed>> newly awarded badges
     */
    public function evaluate(User $plumber, string $event = 'generic', ?int $sourceId = null): array
    {
        if ($plumber->role !== 'plumber' || ! config('reputation.enabled', true)) {
            return [];
        }

        $ctx = $this->context($plumber);
        $awarded = [];

        foreach (Badge::query()->where('is_active', true)->orderBy('sort_order')->get() as $badge) {
            if (UserBadge::query()->where('user_id', $plumber->id)->where('badge_id', $badge->id)->exists()) {
                continue;
            }
            if (! $this->matches($badge, $ctx)) {
                continue;
            }

            UserBadge::query()->create([
                'user_id' => $plumber->id,
                'badge_id' => $badge->id,
                'awarded_at' => now(),
                'source_type' => $event,
                'source_id' => $sourceId,
            ]);

            $payload = [
                'id' => $badge->id,
                'key' => $badge->key,
                'name_ar' => $badge->name_ar,
                'tier' => $badge->tier,
                'icon' => $badge->icon,
            ];
            $awarded[] = $payload;

            if (in_array($badge->tier, ['rare', 'epic', 'legendary'], true)) {
                $this->notifyBadge($plumber, $badge);
            }
        }

        return $awarded;
    }

    /** @return array<string, mixed> */
    protected function context(User $plumber): array
    {
        $score = PlumberScore::query()->where('user_id', $plumber->id)->first();
        $streak = PlumberStreak::query()->where('user_id', $plumber->id)->first();
        $orders = InvoiceDistribution::query()
            ->where('tier', 3)
            ->where('to_user_id', $plumber->id)
            ->where('status', 'confirmed')
            ->count();

        return [
            'user_id' => $plumber->id,
            'orders' => $orders,
            'points' => (int) ($plumber->walletAccounts()->sum('balance_points') ?? 0),
            'streak' => (int) ($streak?->current_weeks ?? 0),
            'level' => $score?->level ?? 'bronze',
            'score' => (int) ($score?->score ?? 0),
            'warranties' => Warranty::query()->where('plumber_id', $plumber->id)->count(),
        ];
    }

    protected function matches(Badge $badge, array $ctx): bool
    {
        $rule = (array) ($badge->rule ?? []);
        $type = (string) ($rule['type'] ?? $badge->key);

        return match ($type) {
            'first_order', 'orders_min' => $ctx['orders'] >= (int) ($rule['min'] ?? 1),
            'points_min' => $ctx['points'] >= (int) ($rule['min'] ?? 1000),
            'streak_min' => $ctx['streak'] >= (int) ($rule['min'] ?? 4),
            'level_min' => $this->levelRank($ctx['level']) >= $this->levelRank((string) ($rule['min'] ?? 'gold')),
            'warranty_min' => $ctx['warranties'] >= (int) ($rule['min'] ?? 1),
            'no_returns_30d' => $ctx['orders'] >= 1 && \App\Models\InvoiceReturn::query()
                ->where('from_user_id', $ctx['user_id'] ?? 0)
                ->whereIn('status', \App\Support\InvoiceReturnStatus::settled())
                ->where('created_at', '>=', now()->subDays(30))
                ->doesntExist(),
            'early_bird', 'mg_loyal', 'big_basket' => false, // تُفعَّل لاحقًا ببيانات أغنى
            default => false,
        };
    }

    protected function levelRank(string $level): int
    {
        return match ($level) {
            'bronze' => 1,
            'silver' => 2,
            'gold' => 3,
            'diamond' => 4,
            default => 0,
        };
    }

    protected function notifyBadge(User $plumber, Badge $badge): void
    {
        try {
            $deep = DeepLink::for('home', 0);
            $asset = $this->cards->assetFor('badge_awarded', [
                'title' => $badge->name_ar,
                'hero' => strtoupper((string) $badge->tier),
                'hero_label' => 'شارة جديدة',
                'client' => $plumber->name,
            ]);
            $text = WhatsAppMessage::make()
                ->header('🏅')
                ->greet($plumber->name)
                ->event('🏅', 'شارة جديدة')
                ->line('*'.$badge->name_ar.'*')
                ->line($badge->description_ar ?: '')
                ->openInApp($deep)
                ->footer()
                ->toString();
            $this->whatsapp->sendMany([[
                'phone' => $plumber->phone,
                'text' => $text,
                'image' => $asset,
                'event' => 'badge_awarded',
                'recipient_type' => 'customer',
                'reference_type' => 'badge',
                'reference_id' => $badge->id,
            ]]);
        } catch (\Throwable) {
            // never break flow
        }
    }

    /** @return array{earned:list,locked:list} */
    public function catalogFor(User $plumber): array
    {
        $earnedIds = UserBadge::query()->where('user_id', $plumber->id)->pluck('badge_id')->all();
        $earned = [];
        $locked = [];
        $ctx = $this->context($plumber);

        foreach (Badge::query()->where('is_active', true)->orderBy('sort_order')->get() as $badge) {
            $item = [
                'id' => $badge->id,
                'key' => $badge->key,
                'name_ar' => $badge->name_ar,
                'description_ar' => $badge->description_ar,
                'tier' => $badge->tier,
                'icon' => $badge->icon,
            ];
            if (in_array($badge->id, $earnedIds, true)) {
                $earned[] = $item;
            } else {
                $item['progress'] = $this->progress($badge, $ctx);
                $locked[] = $item;
            }
        }

        return ['earned' => $earned, 'locked' => $locked];
    }

    /** @return array{current:int,target:int,percent:int} */
    protected function progress(Badge $badge, array $ctx): array
    {
        $rule = (array) ($badge->rule ?? []);
        $type = (string) ($rule['type'] ?? '');
        $target = (int) ($rule['min'] ?? 1);
        $current = match ($type) {
            'orders_min', 'first_order' => $ctx['orders'],
            'points_min' => $ctx['points'],
            'streak_min' => $ctx['streak'],
            'warranty_min' => $ctx['warranties'],
            default => 0,
        };

        return [
            'current' => min($current, $target),
            'target' => $target,
            'percent' => (int) min(100, round(($current / max(1, $target)) * 100)),
        ];
    }
}
