@php
    $get = $field->getGetCallback();
    $points = max(0, (float) ($get('points_per_unit') ?? 0));
    $name = $get('name_ar') ?: $get('name_en') ?: 'المنتج';
    $type = $get('point_value_type');
    $refPrice = (float) ($get('reference_unit_price_dinars') ?? 0);
    $percent = (float) ($get('point_value_percent') ?? 0);
    $fixed = (float) ($get('point_value_fixed') ?? 0);

    $unitValue = 0.0;
    $perPoint = 0.0;

    if ($type === 'percent' && $refPrice > 0 && $percent > 0) {
        $unitValue = round($refPrice * ($percent / 100), 4);
        $perPoint = $points > 0 ? round($unitValue / $points, 4) : 0;
    } elseif ($type === 'fixed' && $fixed > 0 && $points > 0) {
        $perPoint = $fixed;
        $unitValue = round($points * $fixed, 4);
    }

    $qtyExamples = [1, 5, 10];
@endphp

<div class="prod-points-preview" dir="rtl">
    <h4 class="prod-points-title">⭐ النقاط وقيمة التحويل — {{ $name }}</h4>

    <div class="prod-points-flow">
        <div class="prod-points-tier prod-points-tier--blue">
            <span class="prod-points-tier-num">{{ number_format($points, 2) }}</span>
            <span class="prod-points-tier-label">نقطة / وحدة</span>
        </div>
        <div class="prod-points-arrow">←</div>
        <div class="prod-points-tier prod-points-tier--green">
            <span class="prod-points-tier-num">{{ number_format($unitValue, 2) }}</span>
            <span class="prod-points-tier-label">د.ل قيمة الوحدة</span>
        </div>
        @if($perPoint > 0)
            <div class="prod-points-tier prod-points-tier--amber">
                <span class="prod-points-tier-num">{{ number_format($perPoint, 4) }}</span>
                <span class="prod-points-tier-label">د.ل / نقطة</span>
            </div>
        @endif
    </div>

    @if($type === 'percent')
        <p class="prod-points-sub">
            <strong>نسبة:</strong> {{ number_format($percent, 2) }}% من {{ number_format($refPrice, 2) }} د.ل
            = {{ number_format($unitValue, 2) }} د.ل إجمالي قيمة نقاط الوحدة
        </p>
    @elseif($type === 'fixed')
        <p class="prod-points-sub">
            <strong>ثابت:</strong> {{ number_format($fixed, 4) }} د.ل × {{ number_format($points, 2) }} نقطة
            = {{ number_format($unitValue, 2) }} د.ل للوحدة
        </p>
    @else
        <p class="prod-points-sub">اختر نوع التحويل (نسبة أو ثابت) لمعاينة القيمة.</p>
    @endif

    <h5 class="prod-points-subtitle">مسار التوزيع</h5>
    <div class="prod-points-flow prod-points-flow--compact">
        <span class="prod-points-tier-note">① مصنع → جملة</span>
        <span class="prod-points-arrow">←</span>
        <span class="prod-points-tier-note">② جملة → قطاعي</span>
        <span class="prod-points-arrow">←</span>
        <span class="prod-points-tier-note">③ قطاعي → سباك ⭐</span>
    </div>

    @if($unitValue > 0)
        <table class="prod-points-table">
            <thead>
                <tr>
                    <th>كمية مباعة</th>
                    <th>نقاط السباك</th>
                    <th>قيمة تقريبية (د.ل)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($qtyExamples as $qty)
                    <tr>
                        <td>{{ number_format($qty) }} وحدة</td>
                        <td>{{ number_format((int) floor($qty * $points)) }} نقطة</td>
                        <td><strong>{{ number_format($unitValue * $qty, 2) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
