@php
    $get = $field->getGetCallback();
    $items = $get('items') ?? [];
    $qty = 0;
    $pts = 0;
    foreach ($items as $row) {
        $q = (int) ($row['quantity'] ?? 0);
        $qty += $q;
        $pts += (int) floor($q * (float) ($row['points_per_unit'] ?? 0));
    }
@endphp

<div class="ret-totals">
    <div>
        <div class="ret-totals-title">إجمالي المرتجع</div>
        <div class="ret-totals-vals">
            <span class="ret-totals-chip">−{{ number_format($qty) }} وحدة</span>
            <span class="ret-totals-chip">−{{ number_format($pts) }} نقطة</span>
        </div>
    </div>
    <div class="ret-totals-note">
        @if($qty > 0)
            ستُخصم من المستلم وتُعاد للمورّد بعد التأكيد
        @else
            أدخل كمية أكبر من صفر لبند واحد على الأقل
        @endif
    </div>
</div>
