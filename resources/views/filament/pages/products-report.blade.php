@php
    $stats = $this->getSummaryStats();
@endphp

<x-filament::page>
    <div class="prod-report-wrap" dir="rtl">
        {{-- ملخص --}}
        <div class="prod-report-stats">
            <div class="prod-report-stat prod-report-stat--blue">
                <span class="prod-report-stat-num">{{ number_format($stats['totalProducts']) }}</span>
                <span class="prod-report-stat-label">إجمالي المنتجات</span>
            </div>
            <div class="prod-report-stat prod-report-stat--green">
                <span class="prod-report-stat-num">{{ number_format($stats['withPoints']) }}</span>
                <span class="prod-report-stat-label">منتجات لها نقاط</span>
            </div>
            <div class="prod-report-stat prod-report-stat--amber">
                <span class="prod-report-stat-num">{{ number_format($stats['avgPoints'], 2) }}</span>
                <span class="prod-report-stat-label">متوسط النقاط/وحدة</span>
            </div>
            <div class="prod-report-stat prod-report-stat--purple">
                <span class="prod-report-stat-num">{{ number_format($stats['totalSold']) }}</span>
                <span class="prod-report-stat-label">وحدات مباعة (فواتير)</span>
            </div>
            <div class="prod-report-stat prod-report-stat--teal">
                <span class="prod-report-stat-num">{{ number_format($stats['totalAwarded']) }}</span>
                <span class="prod-report-stat-label">⭐ نقاط ممنوحة للسباكين</span>
            </div>
        </div>

        {{-- شرح التوزيع --}}
        <div class="prod-report-flow-info">
            <h3>🔗 كيف تُوزَّع النقاط؟</h3>
            <p>كل منتج له <strong>نقاط/وحدة</strong> ثابتة. عند البيع عبر فاتورة جملة، تمر الكمية بـ 3 طبقات:</p>
            <div class="prod-report-flow-steps">
                <span class="prod-report-flow-step prod-report-flow-step--blue">① مصنع → موزع جملة</span>
                <span class="prod-report-flow-arrow">←</span>
                <span class="prod-report-flow-step prod-report-flow-step--amber">② جملة → قطاعي</span>
                <span class="prod-report-flow-arrow">←</span>
                <span class="prod-report-flow-step prod-report-flow-step--green">③ قطاعي → سباك ⭐</span>
            </div>
            <p class="prod-report-formula">معادلة النقاط: <code>النقاط = الكمية × نقاط/وحدة</code> — التحويل: <code>نسبة من سعر الوحدة</code> أو <code>قيمة ثابتة/نقطة</code> (على كل منتج)</p>
        </div>
    </div>

    {{ $this->table }}
</x-filament::page>
