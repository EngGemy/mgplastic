<?php

namespace App\Services\Ocr;

use App\Models\OcrUsage;
use App\Models\User;
use App\Services\HealthAlertService;
use Illuminate\Support\Facades\Cache;

class OcrQuotaService
{
    public function dailyImageLimitFor(User $user): int
    {
        $map = (array) config('ocr.daily_image_quota', []);

        return max(0, (int) ($map[$user->role] ?? 20));
    }

    public function imagesUsedToday(User $user): int
    {
        return (int) OcrUsage::query()
            ->where('user_id', $user->id)
            ->where('kind', 'upload')
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    public function quotaRemaining(User $user): int
    {
        return max(0, $this->dailyImageLimitFor($user) - $this->imagesUsedToday($user));
    }

    /**
     * @return array{ok:bool, retry_after:?int, remaining:int, limit:int, message_code:?string}
     */
    public function assertCanUpload(User $user): array
    {
        $capacity = $this->assertGlobalGeminiCapacity();
        if (! $capacity['ok']) {
            return [
                'ok' => false,
                'retry_after' => $capacity['retry_after'],
                'remaining' => $this->quotaRemaining($user),
                'limit' => $this->dailyImageLimitFor($user),
                'message_code' => $capacity['message_code'],
            ];
        }

        $limit = $this->dailyImageLimitFor($user);
        $used = $this->imagesUsedToday($user);
        $remaining = max(0, $limit - $used);

        if ($used >= $limit) {
            return [
                'ok' => false,
                'retry_after' => now()->endOfDay()->diffInSeconds(now()),
                'remaining' => 0,
                'limit' => $limit,
                'message_code' => 'ocr_daily_limit_reached',
            ];
        }

        return [
            'ok' => true,
            'retry_after' => null,
            'remaining' => $remaining,
            'limit' => $limit,
            'message_code' => null,
        ];
    }

    /**
     * @return array{ok:bool, retry_after:?int, message_code:?string, used:int, cap:int, ratio:float}
     */
    public function assertGlobalGeminiCapacity(): array
    {
        $cap = max(0, (int) config('ocr.global_gemini_daily_cap', 800));
        $used = $this->globalGeminiUsedToday();
        $ratio = $cap > 0 ? ($used / $cap) : 1.0;
        $alertAt = (float) config('ocr.global_gemini_alert_at', 0.80);

        if ($cap > 0 && $ratio >= $alertAt && $ratio < 1.0) {
            $this->alertCapOnce('ocr_gemini_cap_80', 'OCR Gemini daily cap at '.round($ratio * 100).'%', [
                'used' => $used,
                'cap' => $cap,
            ]);
        }

        if ($cap > 0 && $used >= $cap) {
            $this->alertCapOnce('ocr_gemini_cap_100', 'OCR Gemini daily cap reached', [
                'used' => $used,
                'cap' => $cap,
            ]);

            return [
                'ok' => false,
                'retry_after' => now()->endOfDay()->diffInSeconds(now()),
                'message_code' => 'ocr_capacity_reached',
                'used' => $used,
                'cap' => $cap,
                'ratio' => $ratio,
            ];
        }

        return [
            'ok' => true,
            'retry_after' => null,
            'message_code' => null,
            'used' => $used,
            'cap' => $cap,
            'ratio' => $ratio,
        ];
    }

    public function globalGeminiUsedToday(): int
    {
        return (int) OcrUsage::query()
            ->where('kind', 'gemini')
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    public function recordUpload(?User $user, ?int $batchId, ?int $imageId): void
    {
        OcrUsage::query()->create([
            'user_id' => $user?->id,
            'role' => $user?->role,
            'batch_id' => $batchId,
            'image_id' => $imageId,
            'kind' => 'upload',
            'success' => true,
            'created_at' => now(),
        ]);
    }

    public function recordGemini(
        ?User $user,
        ?int $batchId,
        ?int $imageId,
        ?string $model,
        bool $success,
        ?int $durationMs = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
    ): void {
        OcrUsage::query()->create([
            'user_id' => $user?->id,
            'role' => $user?->role,
            'batch_id' => $batchId,
            'image_id' => $imageId,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'duration_ms' => $durationMs,
            'success' => $success,
            'kind' => 'gemini',
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function alertCapOnce(string $cacheKey, string $reason, array $context): void
    {
        $key = 'ocr:cap_alert:'.$cacheKey.':'.now()->toDateString();
        if (! Cache::add($key, 1, now()->endOfDay())) {
            return;
        }

        try {
            app(HealthAlertService::class)->triggerImmediate($reason, $context);
        } catch (\Throwable) {
            // non-fatal
        }
    }
}
