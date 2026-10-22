@php
    $componentLabels = [
        'volume' => 'حجم التوزيع',
        'consistency' => 'الاتساق الأسبوعي',
        'returns' => 'انخفاض المرتجعات',
        'warranty' => 'نشاط الضمان',
        'tenure' => 'أقدمية الحساب',
    ];
    $eventLabels = [
        'recompute' => 'إعادة حساب',
        'admin_recompute' => 'إعادة حساب (إدارة)',
        'distribution' => 'توزيع',
        'return' => 'مرتجع',
        'warranty' => 'ضمان',
    ];
@endphp

<div class="space-y-5 text-sm" dir="rtl">
    @if (! empty($components) && is_array($components))
        <div>
            <h3 class="text-xs font-bold text-gray-500 mb-2 tracking-wide">مكوّنات الدرجة</h3>
            <div class="grid grid-cols-2 gap-2 mb-2">
                @foreach ($components as $k => $v)
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3">
                        <div class="text-xs text-gray-500">{{ $componentLabels[$k] ?? $k }}</div>
                        <div class="text-2xl font-black text-gray-900 dark:text-white">{{ $v }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if (isset($badges))
        <div>
            <h3 class="text-xs font-bold text-gray-500 mb-2 tracking-wide">الشارات الممنوحة</h3>
            @forelse ($badges as $ub)
                <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100 dark:border-gray-800">
                    <span class="font-bold text-gray-900 dark:text-white">
                        {{ $ub->badge?->name_ar ?? 'شارة #'.$ub->badge_id }}
                    </span>
                    <span class="text-xs text-gray-500">
                        {{ optional($ub->awarded_at)->format('Y/m/d') }}
                        ·
                        {{ ($ub->source_type === 'admin_manual') ? 'يدوي' : 'تلقائي' }}
                    </span>
                </div>
            @empty
                <p class="text-gray-500">لا شارات بعد — يمكن منحها من زر «منح شارة».</p>
            @endforelse
        </div>
    @endif

    <div>
        <h3 class="text-xs font-bold text-gray-500 mb-2 tracking-wide">سجل تحديثات الدرجة</h3>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($events as $e)
                <li class="py-2 flex justify-between gap-4">
                    <span>
                        {{ $eventLabels[$e->type] ?? $e->type }}
                        ·
                        <strong class="{{ ((int) $e->points_delta) >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ ((int) $e->points_delta) > 0 ? '+' : '' }}{{ $e->points_delta }}
                        </strong>
                    </span>
                    <span class="text-gray-500 whitespace-nowrap">{{ optional($e->created_at)->format('Y/m/d H:i') }}</span>
                </li>
            @empty
                <li class="text-gray-500">لا أحداث بعد</li>
            @endforelse
        </ul>
    </div>
</div>
