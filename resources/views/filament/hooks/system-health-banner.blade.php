@php
    try {
        $problems = app(\App\Services\SystemHealthService::class)->bannerProblems();
    } catch (\Throwable) {
        $problems = [];
    }
    $healthUrl = \App\Filament\Pages\SystemHealth::getUrl();
    $criticalCount = collect($problems)->where('status', 'critical')->count();
    $warningCount = collect($problems)->where('status', 'warning')->count();
@endphp

@if($problems !== [])
    <div class="fi-system-health-banner border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm dark:border-amber-900/50 dark:bg-amber-950/40" dir="rtl">
        <div class="mx-auto flex max-w-screen-2xl flex-col gap-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="font-semibold text-amber-950 dark:text-amber-100">
                    تنبيهات صحة النظام
                    @if($criticalCount > 0)
                        <span class="ms-2 inline-flex items-center rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white">{{ $criticalCount }} حرج</span>
                    @endif
                    @if($warningCount > 0)
                        <span class="ms-1 inline-flex items-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-bold text-white">{{ $warningCount }} تحذير</span>
                    @endif
                </div>
                <a href="{{ $healthUrl }}" class="font-medium text-primary-600 underline dark:text-primary-400">
                    فتح صفحة المراقبة
                </a>
            </div>
            <ul class="space-y-1">
                @foreach($problems as $problem)
                    @php
                        $color = ($problem['status'] ?? '') === 'critical'
                            ? 'text-red-700 dark:text-red-300'
                            : 'text-amber-800 dark:text-amber-200';
                    @endphp
                    <li class="{{ $color }}">
                        <a href="{{ $healthUrl }}" class="hover:underline">
                            <span class="font-semibold">{{ $problem['label'] ?? $problem['key'] }}:</span>
                            {{ $problem['details'] ?? $problem['status'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
