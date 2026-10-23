@php
    $get = $field->getGetCallback();
    $items = $get('items') ?? [];
    $qty = 0;
    $pts = 0;
    foreach ($items as $row) {
        $q = (int) ($row['quantity'] ?? 0);
        $qty += $q;
        $sold = max(1, (int) ($row['sold'] ?? 1));
        $awarded = (int) ($row['awarded_points'] ?? 0);
        $returnable = (int) ($row['returnable'] ?? 0);
        $returnablePoints = (int) ($row['returnable_points'] ?? 0);

        if ($q <= 0) {
            continue;
        }
        if ($q >= $returnable && $returnable > 0) {
            $pts += $returnablePoints;
        } else {
            $pts += (int) floor($awarded * $q / $sold);
        }
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
            ستُخصم من المستلم وتُعاد للمورّد بعد التأكيد (حسب نقاط البيع الأصلية)
        @else
            أدخل كمية أكبر من صفر لبند واحد على الأقل
        @endif
    </div>
</div>
