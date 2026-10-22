@php $diag = $this->pushDiagnostics(); @endphp
<x-filament-panels::page>
    <div class="mb-4 rounded-xl border p-4 text-sm leading-relaxed
        {{ $diag['fcm_ready'] && $diag['tokens'] > 0 ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-950' }}"
         dir="rtl">
        <div class="font-bold mb-1">حالة وصول Push للتطبيق</div>
        <div>{{ $diag['summary'] }}</div>
        @if (! $diag['fcm_ready'])
            <div class="mt-2">ضع ملف Service Account واضبط <code>FIREBASE_CREDENTIALS</code> في `.env`.</div>
        @elseif ($diag['tokens'] < 1)
            <div class="mt-2">لا يوجد توكن أجهزة — بعد تسجيل الدخول التطبيق يجب أن يستدعي <code>POST /api/v1/mobile/devices/push-token</code>، وتأكد أن جدول <code>push_tokens</code> موجود (<code>php artisan fcm:ensure-table</code>).</div>
        @else
            <div class="mt-2">تأكد من تشغيل العامل: <code>php artisan queue:work --queue={{ $diag['queue'] }}</code></div>
        @endif
    </div>

    <form wire:submit="send">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" color="primary">
                إرسال الإشعار الآن
            </x-filament::button>

            <x-filament::button
                tag="a"
                :href="\App\Filament\Resources\NotificationBroadcastResource::getUrl()"
                color="gray"
                icon="heroicon-o-archive-box"
            >
                مكتبة الإرسال
            </x-filament::button>

            <x-filament::button
                tag="a"
                :href="\App\Filament\Resources\DatabaseNotificationResource::getUrl()"
                color="gray"
                icon="heroicon-o-bell"
            >
                سجل كل الإشعارات
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

