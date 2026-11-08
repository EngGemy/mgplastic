@php
    $user = auth()->user();
    if (! $user || ! $user->isAdminUser()) {
        return;
    }

    try {
        $problems = app(\App\Services\SystemHealthService::class)->bannerProblems();
    } catch (\Throwable) {
        $problems = [];
    }

    if ($problems === []) {
        return;
    }

    try {
        $healthUrl = \App\Filament\Pages\SystemHealth::getUrl();
    } catch (\Throwable) {
        $healthUrl = \App\Support\AdminPanelPath::url('system-health');
    }

    $criticalCount = collect($problems)->where('status', 'critical')->count();
    $warningCount = collect($problems)->where('status', 'warning')->count();
    $isCritical = $criticalCount > 0;
    $bg = $isCritical
        ? 'linear-gradient(90deg, #7f1d1d 0%, #b91c1c 55%, #dc2626 100%)'
        : 'linear-gradient(90deg, #92400e 0%, #d97706 55%, #f59e0b 100%)';
    $border = $isCritical ? '#991b1b' : '#b45309';
@endphp

<div class="fi-system-health-banner" dir="rtl" style="
    background: {{ $bg }};
    border: 1px solid {{ $border }};
    border-radius: 14px;
    margin: 0 0 1rem;
    padding: 10px 14px;
    font-family: 'Cairo', sans-serif;
    color: #fff;
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
">
    <div style="
        max-width: 100%;
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    ">
        <div style="
            width: 32px; height: 32px; border-radius: 10px;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.28);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        " aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
        </div>

        <div style="flex: 1; min-width: 180px;">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom: 4px;">
                <span style="font-size: 13px; font-weight: 800; letter-spacing: 0.01em;">
                    تنبيهات صحة النظام
                </span>
                @if($criticalCount > 0)
                    <span style="
                        background:#fff; color:#b91c1c;
                        font-size:11px; font-weight:800;
                        padding:2px 8px; border-radius:999px;
                    ">{{ $criticalCount }} حرج</span>
                @endif
                @if($warningCount > 0)
                    <span style="
                        background:rgba(255,255,255,0.92); color:#92400e;
                        font-size:11px; font-weight:800;
                        padding:2px 8px; border-radius:999px;
                    ">{{ $warningCount }} تحذير</span>
                @endif
            </div>

            <div style="display:flex; flex-direction:column; gap:2px;">
                @foreach(array_slice($problems, 0, 3) as $problem)
                    <a href="{{ $healthUrl }}" style="
                        color: rgba(255,255,255,0.95);
                        text-decoration: none;
                        font-size: 12px;
                        line-height: 1.35;
                        display: block;
                        max-width: 100%;
                        overflow: hidden;
                        text-overflow: ellipsis;
                        white-space: nowrap;
                    " title="{{ ($problem['label'] ?? '').': '.($problem['details'] ?? '') }}">
                        <strong style="font-weight:700;">{{ $problem['label'] ?? $problem['key'] }}:</strong>
                        {{ \Illuminate\Support\Str::limit((string) ($problem['details'] ?? $problem['status']), 110) }}
                    </a>
                @endforeach
                @if(count($problems) > 3)
                    <span style="font-size:11px; opacity:0.85;">+{{ count($problems) - 3 }} تنبيهات أخرى</span>
                @endif
            </div>
        </div>

        <a href="{{ $healthUrl }}" style="
            flex-shrink: 0;
            background: #fff;
            color: {{ $isCritical ? '#991b1b' : '#92400e' }};
            font-size: 12px;
            font-weight: 800;
            padding: 8px 14px;
            border-radius: 10px;
            text-decoration: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.12);
            white-space: nowrap;
        ">
            فتح المراقبة
        </a>
    </div>
</div>
