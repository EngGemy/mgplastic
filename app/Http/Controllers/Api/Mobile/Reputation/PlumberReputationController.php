<?php

namespace App\Http\Controllers\Api\Mobile\Reputation;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\PlumberScore;
use App\Models\ReminderPreference;
use App\Models\User;
use App\Models\Warranty;
use App\Services\Reputation\BadgeService;
use App\Services\Reputation\PlumberScoreService;
use App\Services\Reputation\StreakService;
use App\Services\Warranty\WarrantyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlumberReputationController extends Controller
{
    use ApiResponds;

    public function reputation(
        Request $request,
        PlumberScoreService $scores,
        BadgeService $badges,
        StreakService $streaks,
        WarrantyService $warranties,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $row = PlumberScore::query()->where('user_id', $user->id)->first();
        if (! $row) {
            $scores->compute($user);
            $row = PlumberScore::query()->where('user_id', $user->id)->first();
        }
        $score = (int) ($row?->score ?? 0);
        $level = (string) ($row?->level ?? 'bronze');
        $components = [];
        foreach ((array) ($row?->components ?? []) as $k => $v) {
            $components[] = ['key' => $k, 'value' => (int) $v, 'weight' => (int) config("reputation.weights.{$k}", 0)];
        }
        $badgeCatalog = $badges->catalogFor($user);

        return $this->success([
            'score' => $score,
            'level' => $level,
            'level_label' => $scores->levelLabel($level),
            'next_level' => $scores->nextLevel($score),
            'components' => $components,
            'rank_percentile' => null,
            'streak' => $streaks->payload($user),
            'badges' => $badgeCatalog,
            'unlock_warranty' => $warranties->unlockProgress($user),
        ], 'سمعة السباك');
    }

    public function badges(Request $request, BadgeService $badges): JsonResponse
    {
        return $this->success($badges->catalogFor($request->user()), 'الشارات');
    }

    public function streak(Request $request, StreakService $streaks): JsonResponse
    {
        return $this->success($streaks->payload($request->user()), 'السلسلة');
    }

    public function reminderPreferences(Request $request): JsonResponse
    {
        $pref = ReminderPreference::forUser($request->user());

        return $this->success([
            'weekly_enabled' => (bool) $pref->weekly_enabled,
            'daily_points_enabled' => (bool) $pref->daily_points_enabled,
            'quiet_hours' => $pref->quiet_hours,
        ]);
    }

    public function updateReminderPreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'weekly_enabled' => 'sometimes|boolean',
            'daily_points_enabled' => 'sometimes|boolean',
            'quiet_hours' => 'sometimes|array',
        ]);
        $pref = ReminderPreference::forUser($request->user());
        $pref->fill($data)->save();

        return $this->success([
            'weekly_enabled' => (bool) $pref->weekly_enabled,
            'daily_points_enabled' => (bool) $pref->daily_points_enabled,
            'quiet_hours' => $pref->quiet_hours,
        ], 'تم الحفظ');
    }

    public function showPlumber(Request $request, User $plumber, PlumberScoreService $scores, BadgeService $badges): JsonResponse
    {
        if ($plumber->role !== 'plumber') {
            return $this->error('المستخدم ليس سباكًا', 404);
        }
        $row = PlumberScore::query()->where('user_id', $plumber->id)->first();
        $catalog = $badges->catalogFor($plumber);
        $warrantiesCount = Warranty::query()->where('plumber_id', $plumber->id)->where('status', 'active')->count();

        return $this->success([
            'id' => $plumber->id,
            'name' => $plumber->name,
            'score' => (int) ($row?->score ?? 0),
            'level' => $row?->level ?? 'bronze',
            'level_label' => $scores->levelLabel($row?->level ?? 'bronze'),
            'badges' => array_slice($catalog['earned'], 0, 5),
            'active_warranties' => $warrantiesCount,
            'plumber_badge' => [
                'level' => $row?->level ?? 'bronze',
                'top_badges' => array_slice($catalog['earned'], 0, 3),
                'streak_weeks' => (int) (\App\Models\PlumberStreak::query()->where('user_id', $plumber->id)->value('current_weeks') ?? 0),
            ],
        ]);
    }
}
