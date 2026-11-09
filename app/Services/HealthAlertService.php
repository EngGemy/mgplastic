<?php

namespace App\Services;

use App\Mail\SystemHealthAlertMail;
use App\Models\SystemHealthState;
use App\Support\AdminPanelPath;
use App\Support\HealthCheck;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class HealthAlertService
{
    public function __construct(
        protected SystemHealthService $health,
    ) {}

    /**
     * Evaluate checks, persist per-check state, and alert on transitions / repeats / recovery.
     *
     * @return array{alerted: list<string>, recovered: list<string>, repeated: list<string>}
     */
    public function evaluateAndAlert(): array
    {
        $checks = $this->health->checks(liveWahaSession: true);
        $alerted = [];
        $recovered = [];
        $repeated = [];

        $degradedForMail = [];
        $recoveredForMail = [];
        $repeatForMail = [];

        $repeatHours = max(1, (int) config('monitoring.alert_repeat_hours', 6));

        foreach ($checks as $check) {
            $previous = $this->readAlertState($check->key);
            $prevStatus = is_array($previous) ? (string) ($previous['status'] ?? 'ok') : 'ok';
            $lastAlertedAt = is_array($previous) ? ($previous['last_alerted_at'] ?? null) : null;

            $nextStatus = $check->status;
            $isProblem = $check->isProblem();
            $wasProblem = in_array($prevStatus, ['warning', 'critical'], true);

            $shouldAlertNew = $isProblem && ! $wasProblem;
            $shouldRecover = ! $isProblem && $wasProblem;
            $shouldRepeat = false;

            if ($isProblem && $wasProblem && $lastAlertedAt) {
                try {
                    $shouldRepeat = \Illuminate\Support\Carbon::parse($lastAlertedAt)
                        ->lessThanOrEqualTo(now()->subHours($repeatHours));
                } catch (\Throwable) {
                    $shouldRepeat = true;
                }
            } elseif ($isProblem && $wasProblem && ! $lastAlertedAt) {
                $shouldRepeat = true;
            }

            $lastAlerted = $lastAlertedAt;
            if ($shouldAlertNew || $shouldRepeat || $shouldRecover) {
                $lastAlerted = now()->toIso8601String();
            }

            $this->writeAlertState($check->key, [
                'status' => $nextStatus,
                'last_alerted_at' => $lastAlerted,
                'updated_at' => now()->toIso8601String(),
                'details' => $check->details,
            ]);

            if ($shouldAlertNew) {
                $alerted[] = $check->key;
                $degradedForMail[] = $check->toArray();
                $this->notifyAdminsInbox($check, 'degraded');
            } elseif ($shouldRepeat) {
                $repeated[] = $check->key;
                $repeatForMail[] = $check->toArray();
                $this->notifyAdminsInbox($check, 'repeat');
            } elseif ($shouldRecover) {
                $recovered[] = $check->key;
                $recoveredForMail[] = $check->toArray();
                $this->notifyAdminsInbox($check, 'recovered');
            }
        }

        if ($degradedForMail !== []) {
            $this->mail($degradedForMail, 'degraded');
        }
        if ($repeatForMail !== []) {
            $this->mail($repeatForMail, 'repeat');
        }
        if ($recoveredForMail !== []) {
            $this->mail($recoveredForMail, 'recovered');
        }

        // Persist snapshot for the admin banner (no live WAHA on page render).
        $this->health->storeSnapshot($checks);

        return compact('alerted', 'recovered', 'repeated');
    }

    public function triggerImmediate(string $reason, array $context = []): void
    {
        Log::error('[HealthAlert] immediate: '.$reason, $context);

        $title = 'تنبيه فوري: '.$reason;
        $body = $context === [] ? $reason : $reason.' — '.json_encode($context, JSON_UNESCAPED_UNICODE);

        $this->sendAdminInbox($title, $body, 'danger');

        $emails = (array) config('services.waha.alert_emails', []);
        if ($emails === []) {
            return;
        }

        try {
            Mail::to($emails)->send(new SystemHealthAlertMail('degraded', [[
                'key' => 'immediate',
                'label' => $reason,
                'status' => 'critical',
                'value' => $context,
                'details' => $body,
            ]]));
        } catch (\Throwable $e) {
            Log::warning('[HealthAlert] mail failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readAlertState(string $checkKey): ?array
    {
        $payload = SystemHealthState::getPayload(SystemHealthState::alertStateKey($checkKey));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function writeAlertState(string $checkKey, array $state): void
    {
        SystemHealthState::putPayload(SystemHealthState::alertStateKey($checkKey), $state);
    }

    protected function notifyAdminsInbox(HealthCheck $check, string $kind): void
    {
        $title = match ($kind) {
            'recovered' => 'تم الاستعادة: '.$check->label,
            'repeat' => 'لا يزال معطلاً: '.$check->label,
            default => 'تحذير نظام: '.$check->label,
        };

        $status = $kind === 'recovered' ? 'success' : ($check->status === 'critical' ? 'danger' : 'warning');
        $body = ($check->details ?: '').' ['.$check->status.']';

        $this->sendAdminInbox($title, $body, $status);
    }

    protected function sendAdminInbox(string $title, string $body, string $status): void
    {
        $url = AdminPanelPath::url('system-health');

        try {
            AdminNotificationService::sendToRole(
                'admin',
                $title,
                $body,
                $status,
                $url,
                'صحة النظام',
                ['notification_type' => 'system.health', 'type' => 'system.health'],
                sendPush: false,
            );
            AdminNotificationService::sendToRole(
                'super_admin',
                $title,
                $body,
                $status,
                $url,
                'صحة النظام',
                ['notification_type' => 'system.health', 'type' => 'system.health'],
                sendPush: false,
            );
        } catch (\Throwable $e) {
            Log::warning('[HealthAlert] inbox notify failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     */
    protected function mail(array $checks, string $kind): void
    {
        $emails = (array) config('services.waha.alert_emails', []);
        if ($emails === []) {
            return;
        }

        try {
            Mail::to($emails)->send(new SystemHealthAlertMail($kind, $checks));
        } catch (\Throwable $e) {
            Log::warning('[HealthAlert] mail failed', ['error' => $e->getMessage(), 'kind' => $kind]);
        }
    }
}
