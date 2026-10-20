<?php

namespace App\Jobs\Reputation;

use App\Models\User;
use App\Services\Reputation\BadgeService;
use App\Services\Reputation\PlumberScoreService;
use App\Services\Reputation\StreakService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecomputeScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $event = 'generic',
        public ?int $sourceId = null,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return array{new_badges:list,score_delta:int,level_changed:bool}
     */
    public function handle(PlumberScoreService $scores, BadgeService $badges, StreakService $streaks): array
    {
        $user = User::query()->find($this->userId);
        if (! $user || $user->role !== 'plumber') {
            return ['new_badges' => [], 'score_delta' => 0, 'level_changed' => false];
        }

        $result = $scores->compute($user);
        $streaks->markActive($user);
        $newBadges = $badges->evaluate($user, $this->event, $this->sourceId);

        return [
            'new_badges' => $newBadges,
            'score_delta' => $result['delta'],
            'level_changed' => $result['level_changed'],
        ];
    }

    /** Sync helper for request responses. */
    public static function runSync(int $userId, string $event = 'generic', ?int $sourceId = null): array
    {
        return app()->call([new self($userId, $event, $sourceId), 'handle']);
    }
}
