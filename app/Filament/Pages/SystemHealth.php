<?php

namespace App\Filament\Pages;

use App\Services\SecurityAuditService;
use App\Services\SystemHealthService;
use App\Services\WhatsAppNotifier;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SystemHealth extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?int $navigationSort = 110;

    protected static string $view = 'filament.pages.system-health';

    protected static ?string $slug = 'system-health';

    public ?string $testWhatsAppResult = null;

    public static function getNavigationGroup(): ?string
    {
        return 'الإعدادات';
    }

    public static function getNavigationLabel(): string
    {
        return 'صحة النظام';
    }

    public function getTitle(): string
    {
        return 'غرفة المراقبة';
    }

    public function getHeading(): string
    {
        return '';
    }

    /**
     * @return array{verdict: string, label: string, critical: int, warning: int, ok: int}
     */
    public function getOverview(): array
    {
        $checks = $this->getChecks();
        $critical = 0;
        $warning = 0;
        $ok = 0;

        foreach ($checks as $check) {
            match ($check['status'] ?? 'ok') {
                'critical' => $critical++,
                'warning' => $warning++,
                default => $ok++,
            };
        }

        $verdict = $critical > 0 ? 'critical' : ($warning > 0 ? 'warning' : 'ok');
        $label = match ($verdict) {
            'critical' => 'النظام يحتاج تدخّل الآن',
            'warning' => 'يوجد تحذيرات تحتاج متابعة',
            default => 'كل الفحوصات ضمن الحد الطبيعي',
        };

        return compact('verdict', 'label', 'critical', 'warning', 'ok');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isAdminUser());
    }

    /** @return list<array<string, mixed>> */
    public function getChecks(): array
    {
        $snapshot = app(SystemHealthService::class)->cachedSnapshot();
        if (is_array($snapshot['checks'] ?? null) && $snapshot['checks'] !== []) {
            return $snapshot['checks'];
        }

        // First visit with no snapshot yet — DB-only checks (no WAHA HTTP).
        return app(SystemHealthService::class)->checksAsArrays(liveWahaSession: false);
    }

    public function getSnapshotCheckedAt(): ?string
    {
        $snapshot = app(SystemHealthService::class)->cachedSnapshot();

        return is_string($snapshot['checked_at'] ?? null) ? $snapshot['checked_at'] : null;
    }

    /** @return list<array<string, mixed>> */
    public function getFailedJobs(): array
    {
        return app(SystemHealthService::class)->recentFailedJobs(40);
    }

    public function refreshChecks(): void
    {
        app(SystemHealthService::class)->refreshAndStoreSnapshot();
        Notification::make()
            ->success()
            ->title('تم التحديث المباشر')
            ->body('يشمل فحص جلسة WAHA')
            ->send();
    }

    public function resetWhatsAppBreaker(): void
    {
        $notifier = app(WhatsAppNotifier::class);
        $wasOpen = $notifier->isBreakerOpen();
        $reason = $notifier->breakerReason();
        $notifier->resetBreaker('admin_ui');

        app(SecurityAuditService::class)->record(
            'system_health.reset_waha_breaker',
            auth()->user(),
            null,
            ['was_open' => $wasOpen, 'previous_reason' => $reason]
        );

        $health = app(SystemHealthService::class);
        $health->storeSnapshot($health->checks(liveWahaSession: false));

        Notification::make()
            ->success()
            ->title('تم إعادة ضبط قفل واتساب')
            ->body($wasOpen ? 'كان مفتوحاً: '.($reason ?: '—') : 'كان مغلقاً مسبقاً')
            ->send();
    }

    public function sendTestWhatsApp(): void
    {
        $notifier = app(WhatsAppNotifier::class);
        $phones = $notifier->adminPhones();
        $phone = $phones[0] ?? null;

        if (! $phone) {
            $this->testWhatsAppResult = 'لا يوجد رقم في WHATSAPP_ADMIN_PHONES';
            Notification::make()->danger()->title('تعذر الإرسال')->body($this->testWhatsAppResult)->send();

            return;
        }

        $base = rtrim(trim((string) config('services.waha.url')), '/');
        $session = $notifier->sessionName();
        $text = 'اختبار صحة النظام MG Plastic — '.now()->toDateTimeString();

        $httpBody = null;
        $httpStatus = null;
        $ok = false;

        try {
            // Bypass queue + breaker for an explicit admin diagnostic.
            if ($notifier->isBreakerOpen()) {
                $notifier->resetBreaker('admin_test_whatsapp');
            }

            $response = Http::connectTimeout(5)
                ->timeout(15)
                ->withHeaders([
                    'X-Api-Key' => (string) config('services.waha.key'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($base.'/api/sendText', [
                    'session' => $session,
                    'chatId' => $phone.'@c.us',
                    'text' => $text,
                ]);

            $httpStatus = $response->status();
            $httpBody = mb_substr($response->body(), 0, 2000);
            $ok = $response->successful();
        } catch (\Throwable $e) {
            $httpBody = $e->getMessage();
            $httpStatus = null;
        }

        $this->testWhatsAppResult = json_encode([
            'phone' => $phone,
            'http' => $httpStatus,
            'ok' => $ok,
            'body' => $httpBody,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        app(SecurityAuditService::class)->record(
            'system_health.test_whatsapp',
            auth()->user(),
            null,
            ['phone' => $phone, 'http' => $httpStatus, 'ok' => $ok]
        );

        if ($ok) {
            Notification::make()->success()->title('تم إرسال اختبار واتساب')->body('إلى '.$phone)->send();
        } else {
            Notification::make()->danger()->title('فشل اختبار واتساب')->body('HTTP '.($httpStatus ?? 'n/a'))->send();
        }
    }

    public function retryFailedJob(int $id): void
    {
        $uuid = DB::table('failed_jobs')->where('id', $id)->value('uuid');
        if (! $uuid) {
            Notification::make()->danger()->title('المهمة غير موجودة')->send();

            return;
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        app(SecurityAuditService::class)->record(
            'system_health.retry_failed_job',
            auth()->user(),
            null,
            ['failed_job_id' => $id, 'uuid' => $uuid]
        );
        Notification::make()->success()->title('تمت إعادة المحاولة')->body('Job #'.$id)->send();
    }

    public function retryFailedQueue(string $queue): void
    {
        $uuids = DB::table('failed_jobs')
            ->where('queue', $queue)
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('uuid')
            ->all();

        if ($uuids === []) {
            Notification::make()->warning()->title('لا مهام فاشلة في هذا الطابور')->send();

            return;
        }

        Artisan::call('queue:retry', ['id' => $uuids]);
        app(SecurityAuditService::class)->record(
            'system_health.retry_failed_queue',
            auth()->user(),
            null,
            ['queue' => $queue, 'count' => count($uuids)]
        );
        Notification::make()->success()->title('تمت إعادة طابور '.$queue)->body(count($uuids).' مهمة')->send();
    }

    public function deleteFailedJob(int $id): void
    {
        $uuid = DB::table('failed_jobs')->where('id', $id)->value('uuid');
        if (! $uuid) {
            Notification::make()->danger()->title('المهمة غير موجودة')->send();

            return;
        }

        Artisan::call('queue:forget', ['id' => $uuid]);
        app(SecurityAuditService::class)->record(
            'system_health.forget_failed_job',
            auth()->user(),
            null,
            ['failed_job_id' => $id, 'uuid' => $uuid]
        );
        Notification::make()->success()->title('تم الحذف')->body('Job #'.$id)->send();
    }
}
