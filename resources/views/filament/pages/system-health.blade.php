<x-filament-panels::page>
    @php
        $checks = $this->getChecks();
        $failedJobs = $this->getFailedJobs();
        $queues = collect($failedJobs)->pluck('queue')->unique()->values();
    @endphp

    <div class="space-y-6" dir="rtl">
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button wire:click="refreshChecks" color="gray" icon="heroicon-o-arrow-path">
                تحديث
            </x-filament::button>

            <x-filament::button
                wire:click="resetWhatsAppBreaker"
                wire:confirm="هل تريد إعادة ضبط قفل واتساب (Circuit Breaker)؟"
                color="warning"
                icon="heroicon-o-lock-open"
            >
                إعادة ضبط قفل واتساب
            </x-filament::button>

            <x-filament::button wire:click="sendTestWhatsApp" color="primary" icon="heroicon-o-paper-airplane">
                إرسال واتساب تجريبي
            </x-filament::button>
        </div>

        @if($this->testWhatsAppResult)
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="mb-2 font-semibold">نتيجة اختبار واتساب</div>
                <pre class="overflow-x-auto whitespace-pre-wrap text-xs">{{ $this->testWhatsAppResult }}</pre>
            </div>
        @endif

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-4 py-3 font-semibold dark:border-gray-800">فحوصات الصحة</div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 text-right dark:bg-gray-950">
                        <tr>
                            <th class="px-4 py-2">الفحص</th>
                            <th class="px-4 py-2">الحالة</th>
                            <th class="px-4 py-2">القيمة</th>
                            <th class="px-4 py-2">التفاصيل</th>
                            <th class="px-4 py-2">وقت الفحص</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($checks as $check)
                            @php
                                $badge = match($check['status']) {
                                    'critical' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
                                    'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                                    default => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
                                };
                            @endphp
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $check['label'] }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badge }}">
                                        {{ $check['status'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    {{ is_scalar($check['value']) || $check['value'] === null
                                        ? (string) ($check['value'] ?? '—')
                                        : json_encode($check['value'], JSON_UNESCAPED_UNICODE) }}
                                </td>
                                <td class="px-4 py-3">{{ $check['details'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs text-gray-500">{{ $check['checked_at'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <div class="font-semibold">المهام الفاشلة</div>
                <div class="flex flex-wrap gap-2">
                    @foreach($queues as $queue)
                        <x-filament::button
                            size="sm"
                            color="warning"
                            wire:click="retryFailedQueue({{ \Illuminate\Support\Js::from($queue) }})"
                            wire:confirm="إعادة كل مهام طابور {{ $queue }} الفاشلة؟"
                        >
                            إعادة طابور {{ $queue }}
                        </x-filament::button>
                    @endforeach
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 text-right dark:bg-gray-950">
                        <tr>
                            <th class="px-4 py-2">ID</th>
                            <th class="px-4 py-2">الطابور</th>
                            <th class="px-4 py-2">الكلاس</th>
                            <th class="px-4 py-2">فشل في</th>
                            <th class="px-4 py-2">الخطأ</th>
                            <th class="px-4 py-2">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse($failedJobs as $job)
                            <tr wire:key="failed-{{ $job['id'] }}">
                                <td class="px-4 py-3 font-mono text-xs">{{ $job['id'] }}</td>
                                <td class="px-4 py-3">{{ $job['queue'] }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ class_basename($job['job_class']) }}</td>
                                <td class="px-4 py-3 text-xs">{{ $job['failed_at'] }}</td>
                                <td class="px-4 py-3 text-xs max-w-md break-words">{{ $job['error'] }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <x-filament::button size="sm" color="success" wire:click="retryFailedJob({{ $job['id'] }})">
                                            إعادة
                                        </x-filament::button>
                                        <x-filament::button
                                            size="sm"
                                            color="danger"
                                            wire:click="deleteFailedJob({{ $job['id'] }})"
                                            wire:confirm="حذف المهمة الفاشلة #{{ $job['id'] }}؟"
                                        >
                                            حذف
                                        </x-filament::button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500">لا توجد مهام فاشلة</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
