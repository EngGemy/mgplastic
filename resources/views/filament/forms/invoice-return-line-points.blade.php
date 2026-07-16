@php
    $get = $field->getGetCallback();
    $qty = (int) $get('quantity');
    $ppu = (float) $get('points_per_unit');
    $linePts = (int) floor($qty * $ppu);
@endphp

<div class="ret-pts-live">
    <div class="lbl">نقاط هذا البند</div>
    <div class="val">−{{ number_format($linePts) }}</div>
</div>
