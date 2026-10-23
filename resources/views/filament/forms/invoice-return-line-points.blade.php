@php
    $get = $field->getGetCallback();
    $qty = (int) $get('quantity');
    $sold = max(1, (int) $get('sold'));
    $awarded = (int) $get('awarded_points');
    $returnable = (int) $get('returnable');
    $returnablePoints = (int) $get('returnable_points');

    // Preview mirrors CatalogPointsPolicy::clawbackForReturn (awarded snapshot, not catalog).
    if ($qty <= 0) {
        $linePts = 0;
    } elseif ($qty >= $returnable && $returnable > 0) {
        $linePts = $returnablePoints;
    } else {
        $linePts = (int) floor($awarded * $qty / $sold);
    }
@endphp

<div class="ret-pts-live">
    <div class="lbl">نقاط هذا البند</div>
    <div class="val">−{{ number_format($linePts) }}</div>
</div>
