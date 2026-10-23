@php
    $get = $field->getGetCallback();
    $name = $get('product_name') ?: '—';
    $returnable = (int) $get('returnable');
    $returnablePoints = (int) $get('returnable_points');
    $awarded = (int) $get('awarded_points');
    $sold = max(1, (int) $get('sold'));
    $effective = $sold > 0 ? round($awarded / $sold, 2) : 0;
    $ppuLabel = rtrim(rtrim(number_format($effective, 2), '0'), '.') ?: '0';
@endphp

<div class="ret-line">
    <div>
        <div class="ret-prod-name">{{ $name }}</div>
        <div class="ret-pills">
            <span class="ret-pill ret-pill-avail">متاح {{ number_format($returnable) }} وحدة</span>
            <span class="ret-pill ret-pill-ppu">{{ $ppuLabel }} نقطة/وحدة (من البيع)</span>
        </div>
    </div>
    <div class="ret-meta-box ret-meta-avail">
        <div class="lbl">متاح للإرجاع</div>
        <div class="val">{{ number_format($returnable) }}</div>
    </div>
    <div class="ret-meta-box ret-meta-ppu">
        <div class="lbl">نقاط قابلة للإرجاع</div>
        <div class="val">{{ number_format($returnablePoints) }}</div>
    </div>
</div>
