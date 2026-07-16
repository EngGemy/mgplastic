@php
    $get = $field->getGetCallback();
    $name = $get('product_name') ?: '—';
    $returnable = (int) $get('returnable');
    $ppu = (float) $get('points_per_unit');
    $ppuLabel = rtrim(rtrim(number_format($ppu, 2), '0'), '.') ?: '0';
@endphp

<div class="ret-line">
    <div>
        <div class="ret-prod-name">{{ $name }}</div>
        <div class="ret-pills">
            <span class="ret-pill ret-pill-avail">متاح {{ number_format($returnable) }} وحدة</span>
            <span class="ret-pill ret-pill-ppu">{{ $ppuLabel }} نقطة/وحدة</span>
        </div>
    </div>
    <div class="ret-meta-box ret-meta-avail">
        <div class="lbl">متاح للإرجاع</div>
        <div class="val">{{ number_format($returnable) }}</div>
    </div>
    <div class="ret-meta-box ret-meta-ppu">
        <div class="lbl">نقطة / وحدة</div>
        <div class="val">{{ $ppuLabel }}</div>
    </div>
</div>
